<?php

namespace App\Support;

use App\Models\Project;
use App\Models\UnresolvedItem;
use Illuminate\Support\Collection;

/**
 * Copyable prompts that ask the project's coding agent to resolve open unresolved work: one item,
 * or all open items in priority order. No token: the repository is already linked.
 */
final class UnresolvedPrompt
{
    public const MAX_ITEMS = 50;

    public static function forItem(UnresolvedItem $item): string
    {
        $item->loadMissing(['project', 'report']);

        return self::render('agent.unresolved-prompt', [
            'project' => self::oneLine($item->project->name),
            'projectId' => $item->project_id,
            'items' => collect([$item]),
            'single' => true,
        ]);
    }

    /**
     * @param  Collection<int, UnresolvedItem>  $items  open items, newest first (at least the first MAX_ITEMS)
     * @param  int  $total  how many items are open in all
     */
    public static function forAll(Project $project, Collection $items, int $total): string
    {
        return self::render('agent.unresolved-prompt', [
            'project' => self::oneLine($project->name),
            'projectId' => $project->id,
            'items' => $items->take(self::MAX_ITEMS),
            'more' => max(0, $total - self::MAX_ITEMS),
            'single' => false,
        ]);
    }

    /**
     * Asks the agent to record who each open item waits on (client, team, agent), splitting mixed items.
     *
     * @param  Collection<int, UnresolvedItem>  $items  open items to sort
     */
    public static function forSorting(Project $project, Collection $items, int $total): string
    {
        return self::render('agent.unresolved-sort-prompt', [
            'project' => self::oneLine($project->name),
            'projectId' => $project->id,
            'items' => $items->take(self::MAX_ITEMS),
            'more' => max(0, $total - self::MAX_ITEMS),
        ]);
    }

    /**
     * A short message to send the client about an item waiting on them. No internal ids or links.
     */
    public static function clientQuestion(UnresolvedItem $item): string
    {
        $item->loadMissing('project');
        $need = $item->decision_note ?: $item->text;

        return "Hi, for {$item->project->name} we need a decision or input from you before we can continue:\n\n"
            .trim(preg_replace('/\s+/', ' ', $need))."\n\nCould you let us know? Thank you.\n";
    }

    private static function render(string $view, array $data): string
    {
        $text = view($view, $data + ['url' => rtrim((string) config('app.url'), '/'), 'more' => $data['more'] ?? 0])->render();

        return trim(preg_replace("/\n{3,}/", "\n\n", $text))."\n";
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('"', "'", $value)));
    }
}
