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

    /**
     * Pinned verbatim: the Owner may remove an Admin or a Member; only the Owner may remove an
     * Admin; nobody may remove the Owner. The owner appointed the admin (assign admins is
     * owner-only), so letting a second admin undo that appointment hands admins a one-way
     * power over each other that the matrix reserves to the owner.
     */
    public static function canRemoveMember(MemberRole $actor, MemberRole $target): bool
    {
        // match, not a chain of ifs, for the same UnhandledMatchError exhaustiveness as every
        // other method here: a fourth MemberRole would fail loudly at this line rather than
        // silently taking whichever branch an if-chain happened to fall through to.
        return match ($actor) {
            // The owner may remove anyone except another owner - the schema permits exactly
            // one, so $target === Owner never arises in practice, but it is refused here
            // anyway rather than left unhandled.
            MemberRole::Owner => match ($target) {
                MemberRole::Admin, MemberRole::Member => true,
                MemberRole::Owner => false,
            },

            // An admin may remove a Member and nothing above it: removing an Admin is the
            // owner's appointment power, revocable only by the owner who exercised it.
            MemberRole::Admin => match ($target) {
                MemberRole::Member => true,
                MemberRole::Owner, MemberRole::Admin => false,
            },

            // A member removes nobody - remove members names Owner and Admin only.
            MemberRole::Member => match ($target) {
                MemberRole::Owner, MemberRole::Admin, MemberRole::Member => false,
            },
        };
    }

    /**
     * Owner only, and only over a non-owner target. $to is part of the pinned signature but
     * does not gate anything beyond what $actor and $target already decide: refusing
     * $target === Owner already covers "the owner's own role is never edited directly", and
     * the one further rule the spec states - $to === Owner requires the actor to already be
     * Owner - adds nothing once $actor === Owner is already required for anything to pass.
     * $to === Owner is an ownership transfer and $to === $target is a no-op; both are the
     * Owner's to ask for, and the caller - ChangeMemberRole - is the one that must tell them
     * apart: a transfer writes plans.owner_id and demotes the outgoing owner in the same
     * transaction, and a no-op short-circuits before any write at all. This predicate answers
     * only whether the actor may ask, not what the use case must do about the answer.
     */
    public static function canChangeRole(MemberRole $actor, MemberRole $target, MemberRole $to): bool
    {
        // match, not a chain of ifs, for UnhandledMatchError exhaustiveness - see every other
        // method in this file. $to is accepted but not matched on, for the reason above.
        return match ($actor) {
            MemberRole::Owner => $target !== MemberRole::Owner,
            MemberRole::Admin, MemberRole::Member => false,
        };
    }

    /**
     * An Admin may invite only at Member role - assign admins is owner-only, and an admin who
     * could mint an admin-role invite would have promoted someone by the back door. The Owner
     * may invite at Admin or Member, never at Owner: role is already constrained to
     * admin|member at the FormRequest, and this predicate refuses Owner-role invites
     * independently of that, because an owner-role invite would be an ownership transfer that
     * bypasses the transfer endpoint's eligibility checks entirely, regardless of who is
     * asking.
     */
    public static function canInviteWithRole(MemberRole $actor, MemberRole $invited): bool
    {
        return match ($actor) {
            MemberRole::Owner => $invited !== MemberRole::Owner,
            MemberRole::Admin => $invited === MemberRole::Member,
            MemberRole::Member => false,
        };
    }

    /**
     * Reduces to "the owner cannot leave": the schema permits exactly one owner, so there is
     * no "last owner" to count - canLeave needs no member count and no is-this-the-last-one
     * flag, which is a property of the schema rather than a simplification.
     */
    public static function canLeave(MemberRole $role): bool
    {
        return match ($role) {
            MemberRole::Owner => false,
            MemberRole::Admin, MemberRole::Member => true,
        };
    }

    /**
     * Owner only. Not a Capability: blueprint section 9's matrix has no archive row, and
     * Capability's own docblock describes the enum as blueprint section 9's permission matrix
     * "transcribed once into a closed set" - adding an archive row the blueprint never lists
     * would make that description false. canArchive is the fifth non-blueprint predicate
     * alongside canLeave, canRemoveMember, canChangeRole and canInviteWithRole.
     */
    public static function canArchive(MemberRole $role): bool
    {
        return match ($role) {
            MemberRole::Owner => true,
            MemberRole::Admin, MemberRole::Member => false,
        };
    }
}
