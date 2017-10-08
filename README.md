# Purpose
This is an implementation of Atlassian's Smart Commits concept for Perforce Swarm. Atlassian's documentation is [here](https://confluence.atlassian.com/bitbucket/processing-jira-software-issues-with-smart-commit-messages-298979931.html). This implementation is nearly identical to their spec. The general idea is that you can use commands in your changelist description to carry out actions on JIRA issues.

# Usage
On a single line in your changelist description, reference one or more JIRA issues then enter one or more commands. Valid commands are:

* The name of a transition (such as #done)
  * This will perform the specified transition on the issue.
  * Transitions match by name prefix, replacing spaces with -. So "Done" becomes #done, "Start Progress" becomes #start-progress (or just #start), etc.
* #comment [text]
  * This will leave a comment on the task (or worklog, if logging time). The text can be anything so long as it's on the same line.
  * Comments must be followed by the text that you want to be part of the comment. #comment without anything after it will do nothing.
  * You can put other commands after #comment [text] without issue, so "#comment Increased size by 5 #done" would transition the issue to "Done" and leave the comment "Increased size by 5"
* #time [time spent]
  * Time is a set of number-followed-by-letter representing the unit of time. So "4h" would be 4 hours. "4h 30m" would be 4 hours, 30 minutes. "2d 2h" would be 2 days, 2 hours. Etc. You can go all the way up to weeks (w).
  * Note that the project/issue must be configured to support time tracking in order for this to work.

# Notes
Commands _must_ be on the **same line** as the JIRA issue key(s) they apply to. This is because multiple keys can have multiple actions taken on them in a single changelist description (see example below).

Commands _must_ have a space before them. "Removed problematic condition#done" wouldn't work, for example.

# Examples
Anything between the JIRA issue key and commands (or before a JIRA key) is ignored. So, for example, this changelist description:

> [JRA-1234] - Enable Smart Commits for Perforce #done

would transition issue JRA-1234 to Done without any comment or time logging.

> Work done toward JRA-1234, PERFORCE-1000. #comment Got basic framework up and running. #time 1d

This would log 1 day of work with the comment "Got basic framework up and running" on both JRA-1234 and PERFORCE-1000. Multiple issues can be separated by any text, not just commas or whitespace.
Multiple issues can be handled separately if you wish:

> JRA-1234 #done #comment Completed final outstanding work item.
> PERFORCE-1000 #comment Nearly done, just need to add handling for different types of transitions.

Basically all you need to do is reference one or more JIRA issues followed by one or more commands. It tries to intelligently handle text so you can separate changelist descriptions from JIRA comments (perhaps you want to go into more detail in Perforce than is necessary on JIRA, for example).

# Limitations
Until Atlassian provides a way for automation/service users to act on behalf of other users on JIRA server instances (https://jira.atlassian.com/browse/JRASERVER-35124), all commands will be performed by the user configured in the module. Comments will @reference the user that made the commit, if that option is enabled, but will still always come from the configured user.
