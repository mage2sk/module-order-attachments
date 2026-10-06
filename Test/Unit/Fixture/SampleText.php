<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Fixture;

/**
 * Builds scanner test samples from harmless fragments at runtime.
 *
 * The unit tests feed the scanner strings that look like web shells and
 * injection payloads. Keeping those strings split into fragments means the
 * test sources never contain a contiguous malware-like sample, so antivirus
 * software does not flag the package. The joined value is byte-for-byte the
 * same string the scanner receives.
 */
class SampleText
{
    /**
     * Join fragments into the runtime sample string.
     *
     * @param string[] $parts
     * @return string
     */
    public static function join(array $parts): string
    {
        return implode('', $parts);
    }
}
