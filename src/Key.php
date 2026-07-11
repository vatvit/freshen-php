<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\KeyInterface;

class Key implements KeyInterface
{
    private const SEP = '/';

    private string $domain;
    private string $facet;
    private ?string $schemaVersion;
    private ?string $locale;

    /** @var string|array */
    private string|array $idRaw;
    private string $idStr;

    private array $prefixSegments;   // plain segments without id
    private array $fullSegments;     // prefixSegments + [idString]

    private string $prefixStr;       // encoded prefix
    private string $keyStr;          // encoded full key

    public function __construct(
        string           $domain,
        string           $facet,
        string|int|array $id,
        ?string          $schemaVersion = null,
        ?string          $locale = null,
    )
    {
        $this->domain = $this->norm($domain);
        $this->facet = $this->norm($facet);
        $this->schemaVersion = ($schemaVersion !== null && $schemaVersion !== '') ? $this->norm($schemaVersion) : null;
        $this->locale = ($locale !== null && $locale !== '') ? $this->norm($locale) : null;

        // raw id and its storage-ready string form
        if (is_array($id)) {
            $this->idRaw = $this->normalizeParams($id);
            $this->idStr = $this->idStringify($this->idRaw);
        } else {
            $this->idRaw = (string)$id;
            $this->idStr = (string)$id;
        }

        // prefix segments
        $this->prefixSegments = [$this->domain, $this->facet];
        if ($this->schemaVersion !== null) {
            $this->prefixSegments[] = $this->schemaVersion;
        }
        if ($this->locale !== null) {
            $this->prefixSegments[] = $this->locale;
        }
        $this->fullSegments = $this->prefixSegments;
        $this->fullSegments[] = $this->idStr;

        // encoded prefix and full key (explicit loops, no array_map strings)
        $encoded = [];
        foreach ($this->prefixSegments as $seg) {
            $encoded[] = rawurlencode($seg);
        }
        $this->prefixStr = implode(self::SEP, $encoded);
        $this->keyStr = $this->prefixStr . self::SEP . rawurlencode($this->idStr);
    }

    // --- Key ---

    public function toString(): string
    {
        return $this->keyStr;
    }

    public function __toString(): string
    {
        return $this->keyStr;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function facet(): string
    {
        return $this->facet;
    }

    public function schemaVersion(): ?string
    {
        return $this->schemaVersion;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function id(): string|array
    {
        return $this->idRaw;
    }

    public function idString(): string
    {
        return $this->idStr;
    }

    public function prefixString(): string
    {
        return $this->prefixStr;
    }

    public function segments(): array
    {
        return $this->fullSegments;
    }

    public function prefixSegments(): array
    {
        return $this->prefixSegments;
    }

    // --- Extensibility hook ---

    /**
     * Convert composite array id to a deterministic, separator-safe string.
     * Default: canonical JSON → base64url with "j:" prefix (non-reversible, stable).
     * Override in subclasses to change the scheme (e.g., "h:" . hash('sha256', ...)).
     */
    protected function idStringify(array $id): string
    {
        $json = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b64 = base64_encode((string)$json);
        // base64url (explicit, no array_map shortcuts)
        $b64 = str_replace('+', '-', $b64);
        $b64 = str_replace('/', '_', $b64);
        $b64 = rtrim($b64, '=');
        return 'j:' . $b64;
    }

    // --- Helpers ---

    private function norm(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            throw new \InvalidArgumentException('Key segment must be non-empty.');
        }
        return $s;
    }

    /** Canonicalise arrays (stable composite id). */
    private function normalizeParams(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->normalizeParams($v);
            }
        }
        return $a;
    }
}
