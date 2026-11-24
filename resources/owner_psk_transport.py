#!/usr/bin/env python3
"""Private pipe RPC for OCF DTLS PSK. No credentials in argv, environment or logs."""
import base64
import ipaddress
import json
import re
import select
import socket
import sys
import time

try:
    from vendor.smartthings_local_dtls import (
        DtlsError,
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
        self.host = host
        # Socket non connecté : un socket UDP connecté jette silencieusement toute
        # réponse dont le port source diffère du port composé. La pile OCF Samsung
        # peut répondre depuis son propre socket éphémère (upstream #66 / 0.1.14),
        # ce qui se traduisait par un timeout de handshake. On épingle le premier
        # répondeur, comme le relais UDP certificat le fait pour le port 5684.
        self.peer = (host, port)
        self.pinned = False
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            self.sock.bind(('0.0.0.0', source_port))
            self.sock.setblocking(False)
        except Exception:
            self.sock.close()
            self.sock = None
            raise

    def _flush(self, deadline):
        assert self.sock is not None and self.engine is not None
        while True:
            try:
                datagram = self.engine.bio_read(MAX_FRAME_BYTES)
            except WantRead:
                return
            if not datagram:
                continue
            while True:
                try:
                    self.sock.sendto(datagram, self.peer)
                    break
                except BlockingIOError:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        raise TimeoutError('DTLS send timeout')
                    select.select([], [self.sock], [], remaining)

    def _drain(self):
        """Feed the first acceptable datagram and pin the responder port."""
        assert self.sock is not None and self.engine is not None
        while True:
            try:
                datagram, addr = self.sock.recvfrom(MAX_FRAME_BYTES)
            except BlockingIOError:
                return False
            if not datagram or addr[0] != self.host:
                continue
            if self.pinned and addr != self.peer:
                continue
            if not self.pinned:
                self.peer = addr
                self.pinned = True
            self.engine.bio_write(datagram)
            return True

    def _wait(self, deadline):
        assert self.sock is not None and self.engine is not None
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError('DTLS timeout')

            engine_timeout = self.engine.DTLSv1_get_timeout()
            wait_timeout = remaining
            if engine_timeout is not None:
                wait_timeout = min(wait_timeout, max(0.0, engine_timeout))

            readable, _, _ = select.select([self.sock], [], [], wait_timeout)
            if readable and self._drain():
                return

            engine_timeout = self.engine.DTLSv1_get_timeout()
            if engine_timeout is not None and engine_timeout <= 0:
                self.engine.DTLSv1_handle_timeout()
                self._flush(deadline)

    def connect(self, timeout):
        assert self.sock is not None and self.engine is not None
        deadline = time.monotonic() + timeout
        while True:
            try:
                self.engine.do_handshake()
                self._flush(deadline)
                return {'source_port': self.sock.getsockname()[1]}
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


def psk_probe(host, port, timeout=3.0):
    """Preuve du carrier PSK avec une identité/clé jetables, jamais un vrai secret.

    Un appareil PSK-only négocie ECDHE-PSK puis rejette l'identité inconnue
    (alerte fatale 115) ou la mauvaise clé (alerte 20/51) : le carrier est PSK.
    Un appareil à certificat rejette le ClientHello PSK-only (alerte 40) ou reste
    silencieux. Ce handshake complet est bien moins coûteux qu'un échange
    certificat complet, qui peut wedger un lave-linge PSK-only (upstream #504/#852).
    Aucune clé ni identité n'est renvoyée ni journalisée.
    """
    ipaddress.IPv4Address(host)
    if not 1 <= port <= 65535:
        raise ValueError('invalid port')
    engine = DtlsPskClient(identity=b'diagnostic-probe', key=bytes(16), mtu=MTU)
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind(('0.0.0.0', 0))
    sock.setblocking(False)
    peer = (host, port)
    pinned = False
    deadline = time.monotonic() + max(.2, float(timeout))
    outcome = 'no_response'
    alert = None
    carrier = 'unknown'

    def flush():
        while True:
            try:
                datagram = engine.bio_read(MAX_FRAME_BYTES)
            except WantRead:
                return
            if not datagram:
                continue
            try:
                sock.sendto(datagram, peer)
            except BlockingIOError:
                return

    try:
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                outcome = 'timeout'
                break
            try:
                engine.do_handshake()
                outcome = 'connected'
                carrier = 'psk'
                break
            except WantRead:
                pass
            except DtlsError as error:
                match = re.search(r'fatal alert (\d+)', str(error))
                if match:
                    alert = int(match.group(1))
                    if alert == 115:
                        outcome, carrier = 'unknown_psk_identity', 'psk'
                    elif alert in (20, 51):
                        outcome, carrier = 'bad_psk', 'psk'
                    elif alert == 40:
                        outcome = 'handshake_failure'
                    elif alert == 48:
                        outcome = 'unknown_ca'
                    else:
                        outcome = 'alert_%d' % alert
                elif 'unexpected ciphersuite' in str(error):
                    outcome = 'unexpected_ciphersuite'
                elif 'Finished did not verify' in str(error):
                    # Le serveur a négocié ECDHE-PSK et engagé l'échange : carrier PSK
                    # prouvé même si la clé jetable ne correspond pas.
                    outcome, carrier = 'bad_psk', 'psk'
                else:
                    outcome = 'dtls_error'
                break
            flush()
            engine_timeout = engine.DTLSv1_get_timeout()
            wait_timeout = remaining if engine_timeout is None else min(remaining, max(0.0, engine_timeout))
            readable, _, _ = select.select([sock], [], [], wait_timeout)
            if readable:
                while True:
                    try:
                        datagram, addr = sock.recvfrom(MAX_FRAME_BYTES)
                    except BlockingIOError:
                        break
                    if not datagram or addr[0] != host:
                        continue
                    if pinned and addr != peer:
                        continue
                    if not pinned:
                        peer = addr
                        pinned = True
                    engine.bio_write(datagram)
                    break
            engine_timeout = engine.DTLSv1_get_timeout()
            if engine_timeout is not None and engine_timeout <= 0:
                engine.DTLSv1_handle_timeout()
                flush()
        return {'carrier': carrier, 'outcome': outcome, 'alert': alert,
                'source_port': sock.getsockname()[1]}
    finally:
        sock.close()


def check_transport():
    # Embedded NUL bytes are the capability this engine must preserve.
    identity = b'\x37\x71\xf8\xbf\x00\x00\x3a\x2d\xd8\x85\xe4\xc9\x81\x87\x36\xd2'
    validate_credential(identity, bytes(16))
    DtlsPskClient(identity=identity, key=bytes(16), mtu=MTU)


def failure_code(error, action):
    """Return a redacted, actionable RPC code without exposing exception text."""
    safe_action = action if action in ('connect', 'write', 'read', 'close', 'psk_probe') else 'request'
    if isinstance(error, TimeoutError):
        return 'ownerpsk_' + safe_action + '_timeout'
    if safe_action == 'connect' and isinstance(error, DtlsError):
        alert = re.search(r'peer sent fatal alert\s+(\d+)', str(error))
        if alert and int(alert.group(1)) in (20, 51, 115):
            return 'ownerpsk_connect_auth_failed'
        return 'ownerpsk_connect_transient'
    return 'ownerpsk_' + safe_action + '_failed'


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
                result = transport.connect(max(.1, min(30, float(request['timeout']))))
                request.clear()
            elif action == 'psk_probe':
                result = psk_probe(request['host'], int(request['port']),
                                   max(.2, min(10, float(request.get('timeout', 3)))))
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
        print(json.dumps({
            'ok': False,
            'error': 'Session OwnerPSK impossible ou interrompue',
            'code': failure_code(error, action),
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
