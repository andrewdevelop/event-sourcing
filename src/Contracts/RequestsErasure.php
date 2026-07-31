<?php

namespace Core\EventSourcing\Contracts;

/**
 * Marker interface for a DomainEvent that models "this data subject asked to be
 * forgotten" as an ordinary domain fact (e.g. UserRequestedErasure, AccountDeleted).
 *
 * Once such an event is durably committed via EventSourcedRepository::save(), its
 * pii_id is automatically crypto-shredded through PiiAnonymizer::forget() - the same
 * recordThat()/save() flow used for any other event is enough to "delete" a subject,
 * no separate call or CLI command required. See GDPR_CCPA.md.
 */
interface RequestsErasure
{
    // marker only: DomainEvent::getPiiId() already identifies the subject to forget.
}
