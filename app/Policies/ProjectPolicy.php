<?php

namespace App\Policies;

use App\Models\Project;
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
//  Laravel finds this class by itself because it is named after the model.
// ══════════════════════════════════════════════════════════════════

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->accessFor($user) !== null;
    }

    public function update(User $user, Project $project): bool
    {
        return in_array($project->accessFor($user), ['owner', 'admin', 'edit'], true);
    }

    public function copy(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return in_array($project->accessFor($user), ['owner', 'admin'], true);
    }

    public function share(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }
}
