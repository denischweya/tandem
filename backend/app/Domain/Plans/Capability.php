<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Blueprint section 9's permission matrix, transcribed once into a closed set. Case order
 * follows the matrix's own three columns, left to right: the Owner-only rows, then Admin's
 * rows not already covered by Owner, then every Member row. Eight of these - the chat, task
 * and event rows - belong to subsystems Cycle 1 does not build, and are encoded anyway because
 * the matrix is transcribed whole rather than filtered to what has a caller today.
 */
enum Capability
{
    case EditPlan;
    case DeletePlan;
    case ManageMembers;
    case AssignAdmins;
    case RemoveMembers;
    case ManagePlanSettings;
    case InviteMembers;
    case ManageTasks;
    case ModerateChat;
    case ViewPlan;
    case SendMessages;
    case CreateTasks;
    case AssignSelf;
    case CompleteAssignedTasks;
    case CreateEvent;
    case SuggestTimes;
}
