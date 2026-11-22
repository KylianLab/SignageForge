<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Widgets drawn over a screen's content: a clock and the weather in a
 * corner, and a scrolling news ticker at the bottom. Stored as JSON on a
 * screen or a group; this class reads and normalises that array so the rest
 * of the code never deals with missing or invalid keys.
 */
final readonly class Overlays
{
    public const POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
    public const SPEEDS = ['slow' => 25, 'normal' => 15, 'fast' => 9]; // seconds per screen width

    public function __construct(
        public bool $clock = false,
        public string $clockPosition = 'top-right',
        public bool $clockShowDate = true,
        public bool $weather = false,
        public string $weatherPosition = 'top-left',
        public string $weatherLabel = '',
        public ?float $latitude = null,
        public ?float $longitude = null,
        public bool $ticker = false,
        public string $tickerText = '',
        public string $tickerSpeed = 'normal',
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $position = static fn (mixed $value, string $default): string => \is_string($value) && \in_array($value, self::POSITIONS, true) ? $value : $default;
        $float = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;

        return new self(
            clock: true === ($data['clock'] ?? false),
            clockPosition: $position($data['clockPosition'] ?? null, 'top-right'),
            clockShowDate: false !== ($data['clockShowDate'] ?? true),
            weather: true === ($data['weather'] ?? false),
            weatherPosition: $position($data['weatherPosition'] ?? null, 'top-left'),
            weatherLabel: \is_string($data['weatherLabel'] ?? null) ? mb_substr($data['weatherLabel'], 0, 80) : '',
            latitude: $float($data['latitude'] ?? null),
            longitude: $float($data['longitude'] ?? null),
            ticker: true === ($data['ticker'] ?? false),
            tickerText: \is_string($data['tickerText'] ?? null) ? mb_substr($data['tickerText'], 0, 2000) : '',
            tickerSpeed: \is_string($data['tickerSpeed'] ?? null) && isset(self::SPEEDS[$data['tickerSpeed']]) ? $data['tickerSpeed'] : 'normal',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function hasWeatherPlace(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    public function isEmpty(): bool
    {
        return !$this->clock && !($this->weather && $this->hasWeatherPlace()) && !($this->ticker && '' !== trim($this->tickerText));
    }

    /**
     * Ticker messages, one per non-empty line.
     *
     * @return list<string>
     */
    public function tickerMessages(): array
    {
        $lines = preg_split('/\R/', $this->tickerText) ?: [];

        return array_values(array_filter(array_map(trim(...), $lines), static fn (string $line): bool => '' !== $line));
    }
}
