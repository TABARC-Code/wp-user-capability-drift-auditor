## `IdiotsGuide.md`

```markdown
# IdiotsGuide
WP User Capability Drift Auditor

This is for the version of me who knows something feels wrong about permissions but does not want to spelunk through roles and users manually.

## Two concepts you actually need

### Role
A role is a named bundle of capabilities.

Examples:

- subscriber
- author
- editor
- administrator

A user can have one or more roles.

### Capability
A capability is an atomic permission.

Examples:

- edit_posts
- upload_files
- manage_options
- install_plugins

WordPress checks capabilities constantly. If a user has the capability, the user can do the thing.

## Why “drift” happens

Because WordPress is designed to be extended and very little is designed to be cleaned up.

Common causes:

- Plugins adding capabilities, then never removing them on uninstall
- Role editor plugins used once, then forgotten
- Panic promotions, where someone gets admin “just for a minute”
- Migrations and imports dragging roles across environments
- Custom code adding bespoke capabilities, then being removed later

It is not always malicious. It is usually lazy history.

## What this plugin is trying to tell you

Open Tools  
Capability Drift

You get a few key sections.

### High risk capabilities held by non admins

This is the list that matters.

If a non admin user has capabilities like:

- manage_options
- install_plugins
- edit_plugins
- edit_files
- create_users

That user can alter the site at a structural level. This is rarely intended long term.

If this list is not empty, stop pretending permissions are fine.

### Users with direct capability assignments

A user account can have capabilities assigned directly, separate from roles.

This tends to happen when someone wants to grant “one extra thing” without defining a proper role.

It is also easy to forget. That is why this section exists.

If a user has direct caps, it is worth asking:

- why were these added
- are they still needed
- should this be a role instead

### Role drift for default roles

This section compares the site’s current default roles to a baseline.

You see:

- caps added to that role
- caps removed from that role
- any high risk caps in that role

The most common drift horror story is:

Editor role contains manage_options

That turns every editor into a near admin, which is often how “someone deleted the theme settings” happens.

### Orphan looking capabilities

Capabilities that exist but do not match baseline sets.

What they usually are:

- plugin specific capabilities
- leftovers from removed plugins
- typos
- abandoned access control systems

This tool groups them by prefix so you can spot patterns like:

- woocommerce_*
- rankmath_*
- something_weird_*

If the prefix corresponds to a plugin you removed, you may have ghosts.

## What to do with the results

The safe pattern:

1. Identify any non admin users holding high risk capabilities  
2. Identify which role or direct assignment grants that capability  
3. Decide if it is truly required  
4. If not required, remove it carefully using a role editor or code
5. Re run the audit
6. Test the affected user workflow

If you remove capabilities blindly, you will break someone’s workflow and they will end up promoted to admin again, which defeats the point.

Yes, permissions management is a loop of pain.

## Common sense checks

Before you change anything:

- make a database backup
- test on staging if possible
- change one role or one user at a time

Permissions bugs are rarely obvious. They show up as:

- missing menu items
- “you do not have permission” errors
- failed saves
- editors unable to upload images
- someone suddenly unable to publish

All fixable. All annoying.

## Final thought

If this tool shows you unexpected power in unexpected places, that is not the tool being dramatic.
