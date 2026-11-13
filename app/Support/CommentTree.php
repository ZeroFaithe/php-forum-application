<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns a flat list of comment rows into nested view data.
 *
 * Step 1 groups rows by parent_id (null = top level).
 * Step 2 walks the groups depth-first from the top-level comments,
 * computing each comment's displayed depth (top level = 0).
 *
 * Guards:
 *  - indentation is capped at INDENT_CAP while the depth label stays exact;
 *  - traversal stops below MAX_DEPTH levels (traversal limit);
 *  - a node already on the current path is never revisited (cycle guard).
 *    Comments in a disconnected cycle are unreachable from a root and are
 *    therefore not rendered. This is a renderer safeguard, not a
 *    database-wide integrity check.
 */
final class CommentTree
{
    public const INDENT_CAP = 8;
    public const MAX_DEPTH = 50;

    /**
     * @param list<array{id: int, parent_id: ?int}&array<string, mixed>> $rows
     *        Rows already in display order (oldest first).
     * @return array<int|string, list<array<string, mixed>>> parent key => children
     */
    public static function groupByParent(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['parent_id'] === null ? 'root' : (int) $row['parent_id'];
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> nested nodes for the template
     */
    public static function build(array $rows): array
    {
        $groups = self::groupByParent($rows);

        return self::children($groups, 'root', 0, []);
    }

    /**
     * @param array<int|string, list<array<string, mixed>>> $groups
     * @param array<int, true> $path IDs on the path from the root
     * @return list<array<string, mixed>>
     */
    private static function children(array $groups, int|string $parentKey, int $depth, array $path): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $nodes = [];
        foreach ($groups[$parentKey] ?? [] as $row) {
            $id = (int) $row['id'];
            if (isset($path[$id])) {
                continue; // would close a cycle
            }

            $children = self::children($groups, $id, $depth + 1, $path + [$id => true]);

            $nodes[] = $row + [
                'depth'        => $depth,
                'indent'       => min($depth, self::INDENT_CAP),
                'children'     => $children,
                'has_children' => $children !== [],
                'reply_count'  => self::countAll($children),
                'truncated'    => $depth === self::MAX_DEPTH && !empty($groups[$id]),
            ];
        }

        return $nodes;
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function countAll(array $nodes): int
    {
        $count = count($nodes);
        foreach ($nodes as $node) {
            $count += $node['reply_count'];
        }

        return $count;
    }

    /**
     * Flatten nested nodes into display order (handy for tests and debugging).
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array{id: int, depth: int, indent: int}>
     */
    public static function flatten(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = ['id' => (int) $node['id'], 'depth' => $node['depth'], 'indent' => $node['indent']];
            array_push($out, ...self::flatten($node['children']));
        }

        return $out;
    }
}
