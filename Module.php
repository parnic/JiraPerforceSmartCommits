<?php

namespace JiraSmartCommits;

use P4\Spec\Change;
use Zend\Http\Client as HttpClient;
use Zend\Json\Json;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Module
{
    public function onBootstrap(MvcEvent $event)
    {
        $services = $event->getApplication()->getServiceManager();
        $events   = $services->get('queue')->getEventManager();
        $config   = $this->getJiraConfig($services);
        $projects = $this->getProjects();
        $module   = $this;

        // bail out if we lack a host, we won't be able to do anything
        if (!$config['host']) {
            return;
        }

        // connect to worker 1 startup to refresh our cache of jira project ids
        $events->attach(
            'worker.startup',
            function ($event) use ($services, $module) {
                // only run for the first worker.
                if ($event->getParam('slot') !== 1) {
                    return;
                }

                // attempt to request the list of projects, if the request fails keep
                // whatever list we have though as something is better than nothing.
                $cacheDir = $module->getCacheDir();
                $result   = $module->doRequest('get', 'project', null, $services);
                if ($result !== false) {
                    $projects = array();
                    foreach ((array) $result as $project) {
                        if (isset($project['key'])) {
                            $projects[] = $project['key'];
                        }
                    }

                    file_put_contents($cacheDir . '/projects', Json::encode($projects));
                }
            },
            -300
        );

        $events->attach(
            'task.commit',
            function ($event) use ($services, $module) {
                $change = $event->getParam('change');

                if (!$change instanceof Change || !$change->isSubmitted()) {
                    return;
                }

                try {
                    $module->handleSmartCommitMessage($change, $services);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            -300
        );
    }

    public function handleSmartCommitMessage($item, ServiceLocator $services)
    {
        $callouts = $this->getJiraCallouts($item->getDescription());
        $config = $this->getJiraConfig($services);
        $logger = $services->get('logger');

        foreach ($callouts as $callout) {
            if (!array_key_exists('issues', $callout) || !array_key_exists('commands', $callout)) {
                continue;
            }

            foreach ($callout['issues'] as $issue) {
                $commentCommand = $this->getCommand('comment', $callout['commands']);
                $timeCommand = $this->getCommand('time', $callout['commands']);
                $transitionCommands = $this->getTransitionCommands($callout['commands']);

                if ($commentCommand && array_key_exists('args', $commentCommand)) {
                    $logger->info('JiraSmartCommits: building comment');

                    if ($config['cite_submitter_username']) {
                        $prefix = "[~" . $item->getUser() . "] says in c";
                    } else {
                        $prefix = "C";
                    }

                    if ($config['link_changelist_comment_reference']) {
                        $qualifiedUrl = $services->get('viewhelpermanager')->get('qualifiedUrl');
                        $changelist = "[" . $item->getId() . "|" . $qualifiedUrl('change', array('change' => $item->getId())) . "]";
                    } else {
                        $changelist = $item->getId();
                    }

                    $commentStr = "${prefix}hangelist $changelist: ${commentCommand['args']}";
                    $commentObj = array(
                        'body' => $commentStr,
                    );
                }

                if ($timeCommand && array_key_exists('args', $timeCommand)) {
                    $logger->info('JiraSmartCommits: building time tracking');

                    $msg = array(
                        'timeSpent' => $timeCommand['args'],
                    );

                    if (isset($commentStr)) {
                        $logger->info('JiraSmartCommits:    and bundling comment');

                        $msg['comment'] = $commentStr;
                        $commented = true;
                        $timeCommented = true;
                    }

                    if ($this->doRequest(
                        'post',
                        "issue/$issue/worklog",
                        $msg,
                        $services) === false && isset($timeCommented)) {
                        // if time-tracking fails but we wanted a comment, try to still get the comment posted
                        unset($commented);
                    }

                    unset($timeCommented);
                }

                if (count($transitionCommands) > 0) {
                    $logger->info('JiraSmartCommits: asked for transition(s)');

                    $availableTransitions = $this->doRequest(
                        'get',
                        "issue/$issue/transitions",
                        null,
                        $services);

                    $transitionId = null;
                    if ($availableTransitions && is_array($availableTransitions) && array_key_exists('transitions', $availableTransitions)
                        && is_array($availableTransitions['transitions'])) {
                        foreach ($availableTransitions['transitions'] as $availableTransition) {
                            if (is_array($availableTransition) && array_key_exists('name', $availableTransition)) {
                                $availableTransitionName = str_replace(' ', '-', strtolower($availableTransition['name']));
                                $logger->debug("JiraSmartCommits:     available transition: $availableTransitionName");

                                foreach ($transitionCommands as $transitionCommand) {
                                    $logger->debug("JiraSmartCommits:       comparing to ${transitionCommand['command']}");

                                    if (strpos($availableTransitionName, $transitionCommand['command']) === 0) {
                                        $transitionId = $availableTransition['id'];
                                        break;
                                    }
                                }

                                if ($transitionId) {
                                    break;
                                }
                            }
                        }
                    }

                    if ($transitionId) {
                        $logger->info("JiraSmartCommits: found desired transition id $transitionId");

                        $msg = array(
                            'transition' => array(
                                'id' => $transitionId,
                            ),
                        );

                        if (isset($commentObj) && !isset($commented)) {
                            $logger->info('JiraSmartCommits:    bundling comment');

                            $msg['update'] = array(
                                'comment' => array(
                                    array(
                                        'add' => $commentObj,
                                    ),
                                ),
                            );
                            $commented = true;
                            $transitionCommented = true;
                        }

                        if ($this->doRequest(
                            'post',
                            "issue/$issue/transitions",
                            $msg,
                            $services) === false && isset($transitionCommented)) {
                            // if resolving fails but we wanted a comment, try to still get the comment posted
                            unset($commented);
                        }
                    } else {
                        $logger->notice('JiraSmartCommits: unable to locate a valid transition id. ' . print_r($transitionCommands, true) . print_r($availableTransitions, true));
                    }

                    unset($transitionCommented);
                }

                if (isset($commentObj) && !isset($commented)) {
                    $logger->info('JiraSmartCommits: comment wanted, not already handled. handling.');

                    $this->doRequest(
                        'post',
                        "issue/$issue/comment",
                        $commentObj,
                        $services);
                }

                unset($commented, $commentObj, $commentStr);
            }
        }
    }

    private function getCommand($key, $commandArray)
    {
        foreach ($commandArray as $command) {
            if (is_array($command) && array_key_exists('command', $command) && $command['command'] == $key) {
                return $command;
            }
        }

        return null;
    }

    private function getTransitionCommands($commandArray)
    {
        $ret = array();

        foreach ($commandArray as $command) {
            if (is_array($command) && array_key_exists('command', $command) && $command['command'] != 'comment' && $command['command'] != 'time') {
                array_push($ret, $command);
            }
        }

        return $ret;
    }

    public function getJiraCallouts($value)
    {
        $projects = array_map('preg_quote', $this->getProjects());
        $callouts = array();

        foreach (explode("\n", $value) as $line) {
            $mode = 0;
            foreach (preg_split('/(\s+)/', $line) as $word) {
                $issues = $this->getJiraIssue($word, $projects);
                $command = $this->getSmartCommitCommand($word);

                if (!isset($last)) {
                    $last = array();
                }
                if (count($callouts) > 0) {
                    $last = &$callouts[count($callouts)-1];
                }

                if ($command && ($mode === 1 || $mode === 2)) {
                    if (array_key_exists('commands', $last)) {
                        $last['commands'][] = array('command' => $command);
                    } else {
                        $last['commands'] = array(array('command' => $command));
                    }

                    $mode = 2;
                } elseif ($issues) {
                    if ($mode === 0 || !array_key_exists('issues', $last) || array_key_exists('commands', $last)) {
                        $callouts[] = array('issues' => $issues);
                    } else {
                        $last['issues'] = array_merge($last['issues'], $issues);
                    }

                    $mode = 1;
                } elseif ($mode === 2) {
                    $lastCommand = &$last['commands'][count($last['commands'])-1];

                    if (array_key_exists('args', $lastCommand)) {
                        $lastCommand['args'] .= ' ';
                    } else {
                        $lastCommand['args'] = '';
                    }

                    $lastCommand['args'] .= $word;
                }
            }
        }

        return $callouts;
    }

    public function getJiraIssue($value, $projects)
    {
        if (preg_match_all("/((?:" . implode('|', $projects) . ")-[0-9]+)/", $value, $match)) {
            return $match[1];
        }

        return null;
    }

    public function getSmartCommitCommand($value)
    {
        if (strpos($value, '#') === 0 && strlen($value) > 1) {
            return strtolower(substr($value, 1));
        }

        return null;
    }

    public function doRequest($method, $resource, $data, ServiceLocator $services)
    {
        // we commonly do a number of requests and don't want one failure to bork them all,
        // if anything goes wrong just log it
        try {
            // setup the client and request details
            $config = $this->getJiraConfig($services);
            $url    = $config['host'] . '/rest/api/latest/' . $resource;
            $client = new HttpClient;
            $client->setUri($url)
                   ->setHeaders(array('Content-Type' => 'application/json'))
                   ->setMethod($method);

            // set the http client options; including any special overrides for our host
            $commandtions = $services->get('config') + array('http_client_options' => array());
            $commandtions = (array) $commandtions['http_client_options'];
            if (isset($commandtions['hosts'][$client->getUri()->getHost()])) {
                $commandtions = (array) $commandtions['hosts'][$client->getUri()->getHost()] + $commandtions;
            }
            unset($commandtions['hosts']);
            $client->setOptions($commandtions);

            if ($method == 'post') {
                $client->setRawBody(Json::encode($data));
            } else {
                $client->setParameterGet((array) $data);
            }

            if ($config['user']) {
                $client->setAuth($config['user'], $config['password']);
            }

            // attempt the request and log any errors
            $services->get('logger')->info('JIRA making ' . $method . ' request to resource: ' . $url, (array) $data);
            $response = $client->dispatch($client->getRequest());
            if (!$response->isSuccess()) {
                $services->get('logger')->err(
                    'JIRA failed to ' . $method . ' resource: ' . $url . ' (' .
                    $response->getStatusCode() . " - " . $response->getReasonPhrase() . ').',
                    array(
                        'request'   => $client->getLastRawRequest(),
                        'response'  => $client->getLastRawResponse()
                    )
                );
                return false;
            }

            // looks like it worked, return the result
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            // the Jira module is doing this, but it seems to be generating its own exceptions for me.
            // fortunately the error is already logged from above, so we're good.
            //$services->get('logger')->err($e);
        }

        return false;
    }

    public function getProjects()
    {
        $file = DATA_PATH . '/cache/jirasmartcommits/projects';
        if (!file_exists($file)) {
            return array();
        }

        return (array) json_decode(file_get_contents($file), true);
    }

    public function getCacheDir()
    {
        $dir = DATA_PATH . '/cache/jirasmartcommits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }

        return $dir;
    }

    public function getJiraConfig(ServiceLocator $services)
    {
        $config  = $services->get('config');
        $config  = isset($config['jirasmartcommits']) ? $config['jirasmartcommits'] : array();
        $config += array('host' => null, 'user' => null, 'password' => null, 'job_field' => null);

        $config['host'] = rtrim($config['host'], '/');
        if ($config['host'] && strpos(strtolower($config['host']), 'http') !== 0) {
            $config['host'] = 'http://' . $config['host'];
        }
        return $config;
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
