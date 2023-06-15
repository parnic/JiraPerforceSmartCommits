<?php

namespace JiraPerforceSmartCommits\Listener;

use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Events\Listener\AbstractEventListener;
use JiraPerforceSmartCommits\Module;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Laminas\EventManager\Event;
use Laminas\Json\Json;

class Listener extends AbstractEventListener
{
    // connect to worker 1 startup to refresh our cache of jira project ids
    public function refreshProjectList(Event $event)
    {
        parent::log($event);
        if ($event->getParam('slot') !== 1) {
            return;
        }

        // attempt to request the list of projects, if the request fails keep
        // whatever list we have though as something is better than nothing.
        $cacheDir = Module::getCacheDir();
        $result   = Module::doRequest('get', 'project', null, $this->services);
        if ($result !== false) {
            $projects = [];
            foreach ((array) $result as $project) {
                if (isset($project['key'])) {
                    $projects[] = $project['key'];
                }
            }

            file_put_contents($cacheDir . '/projects', Json::encode($projects));
        }
    }

    // when a change is submitted or updated, find any associated JIRA issues;
    // either via associated jobs or callouts in the description, and ensure
    // the JIRA issues link back to the change in Swarm.
    public function checkChange(Event $event)
    {
        parent::log($event);
        $change = $event->getParam('change');
        if (!$change instanceof Change) {
            try {
                $change = Change::fetchById($event->getParam('id'), $this->services->get(ConnectionFactory::P4_ADMIN));
                $event->setParam('change', $change);
            } catch (SpecNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }
        }

        // if this isn't a submitted change; nothing to do
        if (!$change instanceof Change || !$change->isSubmitted()) {
            return;
        }

        try {
            Module::handleSmartCommitMessage($change, $this->services);
        } catch (\Exception $e) {
            $this->services->get(SwarmLogger::SERVICE)->err($e);
        }
    }
}
