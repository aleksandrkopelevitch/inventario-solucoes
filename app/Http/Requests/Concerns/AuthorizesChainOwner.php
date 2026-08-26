<?php

namespace App\Http\Requests\Concerns;

use App\Contracts\ChainCanvas;

/**
 * Authorizes a chain mutation against whichever `ChainCanvas` the route bound.
 *
 * The nine chain requests are shared by two route groups —
 * `diagrams/{diagram}/chain/…` and
 * `submissions/{submission}/diagrams/{diagram}/chain/…` — because the payload
 * they validate is identical: it describes a node or an edge, not who owns
 * one. Only the owner differs, and only for the permission check.
 *
 * Resolved by TYPE rather than by parameter name on purpose. Both groups happen
 * to spell the parameter `{diagram}` today, and it binds a different model in
 * each — which is exactly the coincidence a name-based lookup would be resting
 * on. A name (`$this->route('diagram')`) would also have to grow a branch per
 * owner, in nine files, and the branch that was forgotten would fail open: an
 * `authorize()` that returns false is a visible 403, but one that looks at the
 * wrong parameter and finds null is a 403 on the WORKING path, which reads as
 * a broken canvas.
 */
trait AuthorizesChainOwner
{
    public function authorize(): bool
    {
        $owner = $this->chainOwner();

        return $owner !== null && ($this->user()?->can('update', $owner) ?? false);
    }

    /** The bound canvas, or null when the route has none (never, in practice). */
    protected function chainOwner(): ?ChainCanvas
    {
        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof ChainCanvas) {
                return $parameter;
            }
        }

        return null;
    }
}
