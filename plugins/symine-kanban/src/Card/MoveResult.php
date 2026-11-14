<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Entity\Issue;

/** What became of a request to move or add a card */
final readonly class MoveResult
{
    /** @param list<string> $errors why nothing was changed */
    public function __construct(public ?Issue $issue, public array $errors = [], public int $status = 200)
    {
    }

    public function isSuccess(): bool
    {
        return [] === $this->errors;
    }

    public static function refused(string $error, int $status = 422): self
    {
        return new self(null, [$error], $status);
    }
}
