<?php

/**
 * Calcule la cadence temporaire de reconnexion d'un équipement hors ligne.
 */
final class LocalThingsOfflineBackoff
{
    // Cadence bornée pour le LAN : un appareil endormi ou une IP changée ne doit
    // pas devenir injoignable pendant 30 minutes (upstream #295). Au-delà de trois
    // échecs, la cadence hors ligne configurée reprend.
    private const DELAYS_SECONDS = array(
        30,
        60,
        120,
    );

    /**
     * Retourne le délai après un nombre donné d'échecs consécutifs.
     *
     * Une fois la séquence épuisée, la cadence hors ligne configurée reprend.
     *
     * @param int $failureCount Nombre d'échecs consécutifs.
     * @param int $configuredIntervalSeconds Cadence hors ligne configurée.
     * @return int
     */
    public static function delaySeconds($failureCount, $configuredIntervalSeconds)
    {
        $failureCount = max(0, (int) $failureCount);
        $configuredIntervalSeconds = max(1, (int) $configuredIntervalSeconds);
        if ($failureCount < 1 || $failureCount > count(self::DELAYS_SECONDS)) {
            return $configuredIntervalSeconds;
        }
        return self::DELAYS_SECONDS[$failureCount - 1];
    }

    /**
     * Avance le compteur tout en le bornant au retour à la cadence normale.
     *
     * @param int $failureCount Compteur courant.
     * @return int
     */
    public static function nextFailureCount($failureCount)
    {
        return min(count(self::DELAYS_SECONDS) + 1, max(0, (int) $failureCount) + 1);
    }
}
