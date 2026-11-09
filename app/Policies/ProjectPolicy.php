<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\SharingLink;
use App\Models\User;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ProjectPolicy (who may do what with a study)
//  Location: app/Policies/ProjectPolicy.php
//
//  Only people of the study's own company, and then:
//    owner          everything
//    company admin  everything, on every study of the company
//    shared (edit)  open, change, copy
//    shared (view)  open and copy (the copy is theirs)
//  Only the owner or the company admin may delete a study or choose who it is shared with.
//  The super admin works with companies, never with studies.
//  A page opened through a public sharing link (/shared/{code}/... or /fill/{code}/...) only does what the link allows, for everybody (even the owner
//  who clicks it while signed in): a "view" link is for LOOKING only; a "fill" link also lets the customer save the input pages. Nobody can delete,
//  copy or share the study through a link (see App\Http\Middleware\PublicLink).
//  Laravel finds this class by itself because it is named after the model.
// ══════════════════════════════════════════════════════════════════

class ProjectPolicy
{
    public function view(?User $user, Project $project): bool
    {
        if ($link = $this->publicLink()) {
            return $link->isActive() && (int) $link->project_id === (int) $project->id;
        }

        return $user !== null && $project->accessFor($user) !== null;
    }

    public function update(?User $user, Project $project): bool
    {
        if ($link = $this->publicLink()) {
            return $link->isFill() && $link->isActive() && (int) $link->project_id === (int) $project->id;
        }

        return $user !== null && in_array($project->accessFor($user), ['owner', 'admin', 'edit'], true);
    }

    public function copy(User $user, Project $project): bool
    {
        return ! $this->publicLink() && $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        if ($this->publicLink()) {
            return false;
        }

        return in_array($project->accessFor($user), ['owner', 'admin'], true);
    }

    public function share(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }

    /** The public sharing link this request came through, or null on the pages inside the app. */
    private function publicLink(): ?SharingLink
    {
        $link = request()->attributes->get('public_link');

        return $link instanceof SharingLink ? $link : null;
    }
}
