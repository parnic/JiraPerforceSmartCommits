<?php

use Events\Listener\ListenerFactory as EventListenerFactory;
use Queue\Manager as QueueManager;

$listeners = [JiraPerforceSmartCommits\Listener\Listener::class];
return [
    'listeners' => $listeners,
    'service_manager' =>[
        'factories' => array_fill_keys(
            $listeners,
            Events\Listener\ListenerFactory::class
        )
    ],
    Events\Listener\ListenerFactory::EVENT_LISTENER_CONFIG => [ 
        EventListenerFactory::WORKER_STARTUP => [
            JiraPerforceSmartCommits\Listener\Listener::class => [
                [
                    Events\Listener\ListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    Events\Listener\ListenerFactory::CALLBACK => 'refreshProjectList',
                    Events\Listener\ListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_COMMIT => [
            JiraPerforceSmartCommits\Listener\Listener::class => [
                [
                    Events\Listener\ListenerFactory::PRIORITY => -400,
                    Events\Listener\ListenerFactory::CALLBACK => 'checkChange',
                    Events\Listener\ListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ]
    ],
    'jirasmartcommits' => array(
        'host'      => '',
        'user'      => '',
        'password'  => '',
        'cite_submitter_username' => true, // if Perforce and jira have the same users, leave this option on to reference the submitter in any comment.
        									// vote on https://jira.atlassian.com/browse/JRASERVER-35124 to allow comments to be made on behalf of the submitter
        'link_changelist_comment_reference' => true, // whether to link the changelist number in a comment back to swarm's change
    )
];
