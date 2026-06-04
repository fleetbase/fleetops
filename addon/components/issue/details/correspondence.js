import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const ISSUE_SUBJECT_TYPE = 'Fleetbase\\FleetOps\\Models\\Issue';
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export default class IssueDetailsCorrespondenceComponent extends Component {
    @service store;
    @service notifications;

    @tracked comments = [];

    get resource() {
        return this.args.resource;
    }

    get issueId() {
        return this.resource?.id || this.resource?.public_id || this.resource?.uuid;
    }

    get subjectUuid() {
        if (this.resource?.uuid) {
            return this.resource.uuid;
        }

        if (UUID_PATTERN.test(this.resource?.id ?? '')) {
            return this.resource.id;
        }

        return null;
    }

    get subjectPublicId() {
        return this.resource?.public_id || this.resource?.id || this.resource?.uuid;
    }

    @action loadComments() {
        this.reloadCommentsForIssue();
    }

    @action async reloadCommentsForIssue() {
        const query = {
            withoutParent: 1,
            sort: '-created_at',
            subject_type: ISSUE_SUBJECT_TYPE,
        };

        if (this.subjectUuid) {
            query.subject_uuid = this.subjectUuid;
        } else {
            query.subject = this.subjectPublicId;
        }

        const comments = await this.store.query('comment', query);
        this.comments = comments;

        return comments;
    }

    @action async publishComment(content) {
        const subjectUuid = this.subjectUuid;
        if (!subjectUuid) {
            const error = new Error('Unable to publish comment because the issue UUID is missing.');
            this.notifications.error(error.message);
            throw error;
        }

        const comment = this.store.createRecord('comment', {
            content,
            subject_uuid: subjectUuid,
            subject_type: ISSUE_SUBJECT_TYPE,
        });

        try {
            await comment.save();
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }
}
