<?php

declare(strict_types=1);

namespace App\Service\Account\My;

use App\Entity\User;
use App\Service\MyPageService;

/** What My page shows: the blocks of the user by group, and the variables of the answers to its Ajax actions. */
final class MyPageView
{
    public function __construct(private readonly MyPageService $myPage)
    {
    }

    /** @return array{groups: list<string>, blocks: array<string, list<array<string, mixed>>>, block_options: list<array{0: string, 1: ?string}>} */
    public function page(User $user): array
    {
        $layout = $this->myPage->layout($user);
        $blocks = [];
        foreach (MyPageService::GROUPS as $group) {
            $blocks[$group] = [];
            foreach ($layout[$group] ?? [] as $block) {
                if ($data = $this->myPage->blockData($user, $block)) {
                    $blocks[$group][] = $data;
                }
            }
        }

        return [
            'groups' => MyPageService::GROUPS,
            'blocks' => $blocks,
            'block_options' => $this->blockOptions($user),
        ];
    }

    /**
     * my/update_page.js: the blocks whose settings were submitted, to be drawn again.
     *
     * @param list<array-key> $blocks
     *
     * @return array{blocks: array<array-key, array<string, mixed>|null>} block => its data, null when it is not a block
     */
    public function updatedBlocks(User $user, array $blocks): array
    {
        $data = [];
        foreach ($blocks as $block) {
            $data[$block] = $this->myPage->blockData($user, (string) $block);
        }

        return ['blocks' => $data];
    }

    /**
     * my/add_block.js: the new block and the list of the blocks that can still be added.
     *
     * @return array{block: string, b: array<string, mixed>|null, block_options: list<array{0: string, 1: ?string}>}
     */
    public function addedBlock(User $user, string $block): array
    {
        return [
            'block' => $block,
            'b' => $this->myPage->blockData($user, $block),
            'block_options' => $this->blockOptions($user),
        ];
    }

    /**
     * my/remove_block.js
     *
     * @return array{block: string, block_options: list<array{0: string, 1: ?string}>}
     */
    public function removedBlock(User $user, string $block): array
    {
        return ['block' => $block, 'block_options' => $this->blockOptions($user)];
    }

    /** @return list<array{0: string, 1: ?string}> the options of the "Add" select: the blocks in use cannot be added twice */
    private function blockOptions(User $user): array
    {
        return $this->myPage->blockOptions($this->myPage->blocksInUse($user));
    }
}
