<p align="center">
  <img src=".branding/tabarc-icon.svg" width="180" alt="TABARC-Code Icon">
</p>

# WP User Capability Drift Auditor

Permissions do not stay clean.

A plugin adds capabilities. A developer “temporarily” promotes someone. A migration drags old roles across. A security fix adds a new capability name. Six months later, nobody remembers why an editor can install plugins.

WordPress does not help you see this clearly. It gives you a Role Editor shaped hole, then pretends that is governance.

This plugin is an audit tool. It reports what is actually true right now:

- Which users hold high risk capabilities despite not being administrators  
- Which users have direct capability assignments  
- How default roles have drifted from a baseline  
- Which capabilities look orphaned and should be treated with suspicion  
- Which custom roles exist and what they contain

It does not apply fixes. It does not auto remove anything. It is meant to be safe to run on production, because it is read only.

## Where it lives

Tools  
Capability Drift

Only administrators can access it.

## What it shows

### Summary

A quick snapshot:

- Total roles
- Custom roles
- Users with direct capability assignments
- Non admins with high risk capabilities
- Capabilities that look orphaned

### High risk capabilities held by non admins

This is the important list.

High risk in this context means:

- manage options
- install or activate plugins
- edit theme or plugin files
- manage users
- update core

If a non admin has any of these, the plugin flags it. Loudly.

### Users with direct capability assignments

WordPress allows capabilities to be granted directly to a user account. This bypasses the tidy mental model of roles.

It happens during emergencies. It also happens during bad advice sessions.

This section lists users whose account has direct capabilities assigned.

### Role drift for default roles

For standard roles (subscriber, contributor, author, editor, administrator) the plugin compares the current capability set to a baseline.

It reports:

- capabilities added to that role
- capabilities removed from that role
- any high risk capabilities present in that role

The baseline is best effort and intentionally conservative. The point is to catch obvious drift, not to argue about edge cases.

### Custom roles

Custom roles are shown for visibility. The plugin does not claim to know what custom roles should contain.

It still flags if a custom role includes high risk capabilities, because that is rarely accidental.

### Orphan looking capabilities

These are capabilities found in roles or direct user assignments that are not part of the default role baseline union.

Some are fine:

- plugin specific caps with a clear prefix

Some are not:

- capabilities from plugins removed years ago
- typos that nobody noticed
- half abandoned custom access control systems

This section groups them by prefix to make patterns visible.

## Export

The audit can be exported as JSON via a button on the screen.

Useful for:

- attaching to support tickets
- handing to another dev
- comparing before and after changes

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- Administrator access

## Installation

```bash
cd wp-content/plugins/
git clone https://github.com/TABARC-Code/wp-user-capability-drift-auditor.git
Activate the plugin in the admin, then open:

Tools
Capability Drift

Safety notes
This plugin is read only. It will not change roles, users, or capabilities.

The dangerous part is what you do after reading the report.

If you plan to remove capabilities or rewrite roles:

test on staging

keep a database backup ready

change one thing at a time
re run the audit after each change

Permissions bugs are not dramatic. They are quiet, then expensive. We all understand this.
