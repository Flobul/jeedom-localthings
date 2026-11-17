#!/usr/bin/env python3
"""Private pipe RPC for OCF DTLS PSK. No credentials in argv, environment or logs."""
import base64
import ipaddress
import json
import select
import socket
import sys
import time

try:
    from vendor.smartthings_local_dtls import (
        DtlsPskClient,
        WantRead,
        ZeroReturn,
    )
except Exception:
    print(json.dumps({
        'ok': False,
        'error': 'Transport OwnerPSK indisponible',
        'code': 'ownerpsk_dependency_missing',
    }), flush=True)
    raise SystemExit(1)

MTU = 1200
MAX_FRAME_BYTES = 65535


def validate_credential(identity, key):
    if len(identity) != 16 or identity == bytes(16) or len(key) not in (16, 32):
        raise ValueError('invalid credential')


class Transport:
    def __init__(self, host, port, source_port, identity, key):
        ipaddress.IPv4Address(host)
        validate_credential(identity, key)
        if not 1 <= port <= 65535 or not 0 <= source_port <= 65535:
            raise ValueError('invalid port')

        self.engine = DtlsPskClient(identity=identity, key=key, mtu=MTU)
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            self.sock.bind(('0.0.0.0', source_port))
            self.sock.connect((host, port))
            self.sock.setblocking(False)
        except Exception:
            self.sock.close()
            self.sock = None
            raise

    def _flush(self, deadline):
        while True:
            try:
                datagram = self.engine.bio_read(MAX_FRAME_BYTES)
            except WantRead:
                return
            if not datagram:
                continue
            while True:
                try:
                    written = self.sock.send(datagram)
                    if written != len(datagram):
                        raise RuntimeError('partial DTLS datagram')
                    break
                except BlockingIOError:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        raise TimeoutError('DTLS send timeout')
                    select.select([], [self.sock], [], remaining)

    def _wait(self, deadline):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError('DTLS timeout')

        engine_timeout = self.engine.DTLSv1_get_timeout()
        wait_timeout = remaining
        if engine_timeout is not None:
            wait_timeout = min(wait_timeout, max(0.0, engine_timeout))

        readable, _, _ = select.select([self.sock], [], [], wait_timeout)
        if readable:
            datagram = self.sock.recv(MAX_FRAME_BYTES)
            if datagram:
                self.engine.bio_write(datagram)
            return

        engine_timeout = self.engine.DTLSv1_get_timeout()
        if engine_timeout is not None and engine_timeout <= 0:
            self.engine.DTLSv1_handle_timeout()
            self._flush(deadline)

    def connect(self, timeout):
        deadline = time.monotonic() + timeout
        while True:
            try:
                self.engine.do_handshake()
                self._flush(deadline)
                return
            except WantRead:
                self._flush(deadline)
                self._wait(deadline)

    def write(self, data):
        if not data or len(data) > MAX_FRAME_BYTES:
            raise ValueError('invalid frame')
        deadline = time.monotonic() + 3
        written = self.engine.send(data)
        if written != len(data):
            raise RuntimeError('partial DTLS write')
        self._flush(deadline)

    def read(self, timeout):
        deadline = time.monotonic() + timeout
        while True:
            try:
                return self.engine.recv(MAX_FRAME_BYTES)
            except WantRead:
                self._flush(deadline)
                try:
                    self._wait(deadline)
                except TimeoutError:
                    return None
            except ZeroReturn:
                return None

    def close(self):
        if self.engine is not None:
            try:
                self.engine.shutdown()
                if self.sock is not None:
                    self._flush(time.monotonic() + 1)
            except Exception:
                pass
            self.engine = None
        if self.sock is not None:
            self.sock.close()
            self.sock = None


def check_transport():
    # Embedded NUL bytes are the capability this engine must preserve.
    identity = b'\x37\x71\xf8\xbf\x00\x00\x3a\x2d\xd8\x85\xe4\xc9\x81\x87\x36\xd2'
    validate_credential(identity, bytes(16))
    DtlsPskClient(identity=identity, key=bytes(16), mtu=MTU)


def main():
    transport = None
    action = ''
    try:
        while True:
            line = sys.stdin.buffer.readline(200000)
            if not line:
                break
            if not line.endswith(b'\n'):
                raise ValueError('oversized request')
            request = json.loads(line)
            action = request['action']
            result = None
            if action == 'connect':
                if transport:
                    raise ValueError('already connected')
                transport = Transport(
                    request['host'],
                    int(request['port']),
                    int(request['source_port']),
                    bytes.fromhex(request['identity']),
                    bytes.fromhex(request['key']),
                )
                transport.connect(max(.1, min(30, float(request['timeout']))))
                request.clear()
            elif action == 'write':
                if not transport:
                    raise ValueError('not connected')
                transport.write(base64.b64decode(request['data'], validate=True))
            elif action == 'read':
                if not transport:
                    raise ValueError('not connected')
                data = transport.read(max(.01, min(30, float(request['timeout']))))
                result = None if data is None else base64.b64encode(data).decode('ascii')
            elif action == 'close':
                if transport:
                    transport.close()
                    transport = None
            else:
                raise ValueError('invalid action')
            print(json.dumps({'ok': True, 'result': result}), flush=True)
    except Exception as error:
        # Protocol, JSON and credential errors must never expose their values.
        safe_action = action if action in ('connect', 'write', 'read', 'close') else 'request'
        suffix = '_timeout' if isinstance(error, TimeoutError) else '_failed'
        print(json.dumps({
            'ok': False,
            'error': 'Session OwnerPSK impossible ou interrompue',
            'code': 'ownerpsk_' + safe_action + suffix,
        }), flush=True)
    finally:
        if transport:
            transport.close()


if __name__ == '__main__':
    if sys.argv[1:] == ['--check']:
        try:
            check_transport()
            print('OwnerPSK binary-identity transport available')
        except Exception:
            print('OwnerPSK requires Python 3 and python3-cryptography')
            sys.exit(1)
    else:
        main()
