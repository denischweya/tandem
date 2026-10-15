<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Blueprint section 9's permission matrix, pure: no database, no clock, no model. Every method
 * is static, takes enums and primitives only, and returns a bool. The Application layer
 * resolves an actor's MemberRole and MemberStatus once per request
 * (PlanRepository::findVisibleTo()) and hands them here as values - this class never reaches
 * for a model to find out who is asking.
 */
final class PlanPermissions
{
    /**
     * Blueprint section 9's roles-vs-statuses split: a status decides WHETHER a member has
     * any access at all, before role decides WHAT they may do with it.
     */
    public static function grantsAccess(MemberStatus $status): bool
    {
        // match, not a chain of ifs: adding a case to MemberStatus without handling it here
        // throws UnhandledMatchError rather than silently falling through to a default, which
        // is what makes the exhaustiveness test on this method meaningful.
        return match ($status) {
            // Active is the only status that confers anything. DeleteAccount::successorFor()
            // already reads this way ("handing a Plan to someone who was invited and never
            // accepted gives it an owner who cannot see it"), so Invited granting access would
            // contradict code this project already ships.
            MemberStatus::Active => true,

            // A pending invite is not membership yet - plan_invites is the entire pending
            // state, and plan_members only ever reaches Active on acceptance.
            MemberStatus::Invited => false,

            // Left and Removed rows are permanent (UNIQUE(plan_id, user_id) forces a re-invite
            // to reuse the row rather than insert a second one), so a former member must never
            // be resurrected by anything except an explicit acceptance that sets status back
            // to Active.
            MemberStatus::Left => false,
            MemberStatus::Removed => false,
        };
    }

    /**
     * Owner contains Admin contains Member: every capability granted to a weaker role is
     * granted to a stronger one, stated explicitly rather than left for a caller to infer from
     * case order.
     */
    public static function allows(MemberRole $role, Capability $capability): bool
    {
        // match over Capability, each arm a match over MemberRole: an added Capability without
        // a handled arm here throws UnhandledMatchError rather than silently defaulting to
        // refused (or, worse, allowed) - which is what makes the matrix test meaningful, and
        // the same exhaustiveness reasoning RotationPolicy already applies to RefreshTokenState.
        return match ($capability) {
            // Owner-only: delete Plan appears under Owner and nowhere else in the matrix.
            Capability::DeletePlan => match ($role) {
                MemberRole::Owner => true,
                MemberRole::Admin, MemberRole::Member => false,
            },

            // Owner-only: manage members is the owner's authority over the member list itself
            // - admin-versus-admin removal and the invite-list routes both turn on this NOT
            // being Admin's.
            Capability::ManageMembers => match ($role) {
                MemberRole::Owner => true,
                MemberRole::Admin, MemberRole::Member => false,
            },

            // Owner-only: assign admins appears under Owner and nowhere under Admin, which is
            // what makes an admin promoting a peer the privilege escalation the matrix forbids
            // by omission.
            Capability::AssignAdmins => match ($role) {
                MemberRole::Owner => true,
                MemberRole::Admin, MemberRole::Member => false,
            },

            // Owner-only: no screen and no route lets anyone but the owner touch Plan settings.
            Capability::ManagePlanSettings => match ($role) {
                MemberRole::Owner => true,
                MemberRole::Admin, MemberRole::Member => false,
            },

            // Owner and Admin both: edit Plan is listed under both columns verbatim.
            Capability::EditPlan => match ($role) {
                MemberRole::Owner, MemberRole::Admin => true,
                MemberRole::Member => false,
            },

            // Owner and Admin both: remove members is listed under both columns verbatim -
            // canRemoveMember below is the finer-grained rule for WHICH member an admin may
            // remove, and sits on top of this coarser capability check.
            Capability::RemoveMembers => match ($role) {
                MemberRole::Owner, MemberRole::Admin => true,
                MemberRole::Member => false,
            },

            // Admin's own row, not shared with Owner by the matrix text, but Owner gets it
            // anyway under the owner-contains-admin decision.
            Capability::InviteMembers => match ($role) {
                MemberRole::Owner, MemberRole::Admin => true,
                MemberRole::Member => false,
            },

            // Deferred to the Tasks cycle, encoded anyway because spec section 12 asks for the
            // complete section 9 matrix. Same shape as InviteMembers: Owner and Admin, never
            // Member.
            Capability::ManageTasks => match ($role) {
                MemberRole::Owner, MemberRole::Admin => true,
                MemberRole::Member => false,
            },

            // Deferred to the Chat cycle, encoded anyway for the same reason as ManageTasks.
            Capability::ModerateChat => match ($role) {
                MemberRole::Owner, MemberRole::Admin => true,
                MemberRole::Member => false,
            },

            // Member's row, and every role above Member inherits it under the owner-contains-
            // admin-contains-member decision - view Plan is the one capability every active
            // membership carries, which is also why 404-over-403 is the right shape for a
            // non-member rather than a 403 from this method.
            Capability::ViewPlan => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },

            // Member's row, inherited upward. Deferred to the Chat cycle.
            Capability::SendMessages => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },

            // Member's row, inherited upward. Deferred to the Tasks cycle.
            Capability::CreateTasks => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },

            // Member's row, inherited upward. Deferred to the Tasks cycle.
            Capability::AssignSelf => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },

            // Member's row, inherited upward. Deferred to the Tasks cycle.
            Capability::CompleteAssignedTasks => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },

            // Member's row, inherited upward. PROVISIONAL: blueprint section 9 says "if
            // permitted" and no column holds that condition yet - encoded here as role-gated
            // only, and the Events cycle adds the condition together with plan_events.
            Capability::CreateEvent => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },

            // Member's row, inherited upward. Deferred to the Events cycle.
            Capability::SuggestTimes => match ($role) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => true,
            },
        };
    }
}
