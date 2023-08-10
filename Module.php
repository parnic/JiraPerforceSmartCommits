<?php

namespace JiraPerforceSmartCommits;

use Application\Config\ConfigManager;
use Laminas\Http\Client as HttpClient;
use Laminas\Json\Json;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Module
{
    public static function handleSmartCommitMessage($item, ServiceLocator $services)
    {
        $callouts = self::getJiraCallouts($item->getDescription());
        $config = $services->get('config');
        $logger = $services->get('logger');
        $transitionsHaveScreens = ConfigManager::getValue($config, 'jirasmartcommits.transitions_have_screens');
        $clField = ConfigManager::getValue($config, 'jirasmartcommits.fixed_changelist_field');

        foreach ($callouts as $callout) {
            if (!array_key_exists('issues', $callout) || !array_key_exists('commands', $callout)) {
                continue;
            }

            foreach ($callout['issues'] as $issue) {
                $commentCommand = self::getCommand('comment', $callout['commands']);
                $timeCommand = self::getCommand('time', $callout['commands']);
                $transitionCommands = self::getTransitionCommands($callout['commands']);

                if ($commentCommand && array_key_exists('args', $commentCommand)) {
                    $logger->info('JiraSmartCommits: building comment');

                    if (ConfigManager::getValue($config, 'jirasmartcommits.cite_submitter_username', true)) {
                        $prefix = "[~" . $item->getUser() . "] says in c";
                    } else {
                        $prefix = "C";
                    }

                    if (ConfigManager::getValue($config, 'jirasmartcommits.link_changelist_comment_reference', true)) {
                        $qualifiedUrl = $services->get('ViewHelperManager')->get('qualifiedUrl');
                        $changelist = "[" . $item->getId() . "|" . $qualifiedUrl('change', array('change' => $item->getId())) . "]";
                    } else {
                        $changelist = $item->getId();
                    }

                    $commentStr = "{$prefix}hangelist $changelist: {$commentCommand['args']}";
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

                    if (self::doRequest(
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

                    $availableTransitions = self::doRequest(
                        'get',
                        "issue/$issue/transitions",
                        null,
                        $services);

                    $transitionId = null;
                    $transitionToStatusType = null;
                    if ($availableTransitions && is_array($availableTransitions) && array_key_exists('transitions', $availableTransitions)
                        && is_array($availableTransitions['transitions'])) {
                        foreach ($availableTransitions['transitions'] as $availableTransition) {
                            if (is_array($availableTransition) && array_key_exists('name', $availableTransition)) {
                                $availableTransitionName = str_replace(' ', '-', strtolower($availableTransition['name']));
                                $logger->debug("JiraSmartCommits:     available transition: $availableTransitionName");

                                foreach ($transitionCommands as $transitionCommand) {
                                    $logger->debug("JiraSmartCommits:       comparing to {$transitionCommand['command']}");

                                    if (strpos($availableTransitionName, $transitionCommand['command']) === 0) {
                                        $transitionId = $availableTransition['id'];

                                        if (isset($availableTransition['to']) && isset($availableTransition['to']['statusCategory'])) {
                                            $transitionToStatusType = $availableTransition['to']['statusCategory']['key'];
                                        }
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

                        if (isset($commentObj) && !isset($commented) && $transitionsHaveScreens) {
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

                        if (self::doRequest(
                            'post',
                            "issue/$issue/transitions",
                            $msg,
                            $services) === false && isset($transitionCommented)) {
                            // if resolving fails but we wanted a comment, try to still get the comment posted
                            unset($commented);
                        }

                        // todo: respect $transitionsHaveScreens value before doing this separately
                        if ($clField && $transitionId) {
                            $logger->info('JiraSmartCommits: setting changelist field value along with transition.');

                            $clFieldNumeric = ConfigManager::getValue($config, 'jirasmartcommits.fixed_changelist_field_is_numeric');
                            $clObj = array($clField => "{$item->getId()}");
                            if ($clFieldNumeric) {
                                $clObj = array($clField => $item->getId());
                            }

                            // if this isn't a "done" transition, make sure the fixed field is nulled out
                            if ($transitionToStatusType && $transitionToStatusType !== 'done') {
                                $logger->debug("JiraSmartCommits: nulling changelist field value because target status is not a Done status (is {$transitionToStatusType}).");
                                $clObj = array($clField => null);
                            } else {
                                $logger->debug("JiraSmartCommits: desired status is a 'done' status type, continuing with setting fixed field to {$item->getId()}.");
                            }

                            $msg = array(
                                'fields' => $clObj,
                            );

                            self::doRequest(
                                'put',
                                "issue/$issue",
                                $msg,
                                $services);
                        }
                    } else {
                        $logger->notice('JiraSmartCommits: unable to locate a valid transition id. ' . print_r($transitionCommands, true) . print_r($availableTransitions, true));
                    }

                    unset($transitionCommented);
                }

                if (isset($commentObj) && !isset($commented)) {
                    $logger->info('JiraSmartCommits: comment wanted, not already handled. handling.');

                    self::doRequest(
                        'post',
                        "issue/$issue/comment",
                        $commentObj,
                        $services);
                }

                unset($commented, $commentObj, $commentStr);
            }
        }
    }

    private static function getCommand($key, $commandArray)
    {
        foreach ($commandArray as $command) {
            if (is_array($command) && array_key_exists('command', $command) && $command['command'] == $key) {
                return $command;
            }
        }

        return null;
    }

    private static function getTransitionCommands($commandArray)
    {
        $ret = array();

        foreach ($commandArray as $command) {
            if (is_array($command) && array_key_exists('command', $command) && $command['command'] != 'comment' && $command['command'] != 'time') {
                array_push($ret, $command);
            }
        }

        return $ret;
    }

    public static function getJiraCallouts($value)
    {
        $projects = array_map('preg_quote', self::getProjects());
        $callouts = array();

        foreach (explode("\n", $value) as $line) {
            $mode = 0;
            foreach (preg_split('/(\s+)/', $line) as $word) {
                $issues = self::getJiraIssue($word, $projects);
                $command = self::getSmartCommitCommand($word);

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

    public static function getJiraIssue($value, $projects)
    {
        if (preg_match_all("/((?:" . implode('|', $projects) . ")-[0-9]+)/", $value, $match)) {
            return $match[1];
        }

        return null;
    }

    public static function getSmartCommitCommand($value)
    {
        if (strpos($value, '#') === 0 && strlen($value) > 1) {
            return strtolower(substr($value, 1));
        }

        return null;
    }

    public static function doRequest($method, $resource, $data, ServiceLocator $services)
    {
        // we commonly do a number of requests and don't want one failure to bork them all,
        // if anything goes wrong just log it
        try {
            // setup the client and request details
            $config = $services->get('config');
            $url    = ConfigManager::getValue($config, 'jirasmartcommits.host') . '/rest/api/latest/' . $resource;
            $client = new HttpClient;
            $client->setUri($url)
                   ->setHeaders(array('Content-Type' => 'application/json'))
                   ->setMethod($method);

            // set the http client options; including any special overrides for our host
            $options = $services->get('config') + array('http_client_options' => array());
            $options = (array) $options['http_client_options'];
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            $client->setOptions($options);

            if ($method == 'post' || $method == 'put') {
                $client->setRawBody(Json::encode($data));
            } else {
                $client->setParameterGet((array) $data);
            }

            if (ConfigManager::getValue($config, 'jirasmartcommits.user')) {
                $client->setAuth(ConfigManager::getValue($config, 'jirasmartcommits.user'), ConfigManager::getValue($config, 'jirasmartcommits.password'));
            }

            // attempt the request and log any errors
            $services->get('logger')->info('JiraSmartCommits making ' . $method . ' request to resource: ' . $url, (array) $data);
            $response = $client->dispatch($client->getRequest());
            if (!$response->isSuccess()) {
                $services->get('logger')->err(
                    'JiraSmartCommits failed to ' . $method . ' resource: ' . $url . ' (' .
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

    public static function getProjects()
    {
        $file = DATA_PATH . '/cache/jirasmartcommits/projects';
        if (!file_exists($file)) {
            return array();
        }

        return (array) json_decode(file_get_contents($file), true);
    }

    public static function getCacheDir()
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

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
