<?php

return array(
    'jirasmartcommits' => array(
        'host'      => '',
        'user'      => '',
        'password'  => '',
        'cite_submitter_username' => true, // if Perforce and jira have the same users, leave this option on to reference the submitter in any comment.
        									// vote on https://jira.atlassian.com/browse/JRASERVER-35124 to allow comments to be made on behalf of the submitter
        'link_changelist_comment_reference' => true, // whether to link the changelist number in a comment back to swarm's change
    )
);
