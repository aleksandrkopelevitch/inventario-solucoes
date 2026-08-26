<?php

namespace App\View\Components\Submissions;

use App\Models\Diagram;
use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The one thing an approval leaves open: getting the approved TO BE into the
 * catalog's own topology.
 *
 * Renders nothing until there is something to hand off, which is most of the
 * time — a card that is permanently present and permanently empty teaches
 * people to stop reading that column.
 */
class TopologyHandoff extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-topology-handoff-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->submission->loadMissing(['approvedTopology.diagram', 'approvedTopology.appliedBy', 'solution']);

        $topology = $this->submission->approvedTopology;

        return view('components.submissions.topology-handoff', [
            'domId'    => self::DOM_ID,
            'topology' => $topology,
            'canEdit'  => auth()->user()?->can('update', $this->submission) ?? false,
            // Only the diagrams the solution takes part in: applying onto
            // someone else's diagram is a write nobody would look for
            // (enforced in ApplyApprovedTopologyRequest too — this list only
            // keeps the picker from offering it).
            'targets' => $topology?->isPending() && $this->submission->solution
                ? $this->submission->solution->diagrams()->orderBy('name')->get(['diagrams.id', 'diagrams.name'])
                : collect(),
        ]);
    }
}
