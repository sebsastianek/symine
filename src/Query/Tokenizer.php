<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
/** Port of Redmine::Search::Tokenizer */
#[Exclude]
final class Tokenizer
{
    public const int MAX_TOKENS = 5;

    /** @var list<string>|null */
    private ?array $all = null;

    public function __construct(private readonly ?string $question)
    {
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return \array_slice($this->allTokens(), 0, self::MAX_TOKENS);
    }

    public function isTruncated(): bool
    {
        return \count($this->allTokens()) > self::MAX_TOKENS;
    }

    /** @return list<string> */
    private function allTokens(): array
    {
        if (null !== $this->all) {
            return $this->all;
        }
        preg_match_all('/"[^"]+"|[^\p{Zs}\s]+/u', (string) $this->question, $m);
        $tokens = [];
        foreach ($m[0] as $t) {
            $t = preg_replace('/\A"\p{Zs}*|\p{Zs}*"\z/u', '', $t) ?? $t;
            if (mb_strlen($t) > 1 || preg_match('/\p{Han}/u', $t)) {
                $tokens[$t] = true;
            }
        }

        return $this->all = array_map('strval', array_keys($tokens));
    }
}
