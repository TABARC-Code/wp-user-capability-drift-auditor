<?php
/**
 * Plugin Name: WP User Capability Drift Auditor
 * Plugin URI: https://github.com/TABARC-Code/wp-user-capability-drift-auditor
 * Description: Audits roles and users for capability drift, direct user overrides, and high risk permissions that tend to appear over time.
 * Version: 1.0.0
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Why this exists:
 * Permissions on WordPress sites do not stay stable. Plugins add caps. Themes add caps. Someone "temporarily" promotes an editor.
 * Then six months later, nobody remembers why an author can manage_options.
 *
 * WordPress provides almost no useful visibility into:
 * - what roles currently contain
 * - what users have been granted directly
 * - who can do high risk actions besides admins
 *
 * This plugin is an audit tool.
 * It does not remove caps. It does not rewrite roles. It does not "optimise".
 * It prints the uncomfortable truth and lets me decide what to do next.
 *
 * TODO: add a per user drilldown screen with a clean diff view.
 * TODO: add optional multisite network view.
 * FIXME: baseline comparisons are best effort for default roles only. Custom roles are reported, but not judged.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_User_Capability_Drift_Auditor' ) ) {

    class WP_User_Capability_Drift_Auditor {

        private $screen_slug = 'wp-user-capability-drift-auditor';
        private $export_action = 'wucda_export_json';

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
            add_action( 'admin_post_' . $this->export_action, array( $this, 'handle_export_json' ) );
            add_action( 'admin_head-plugins.php', array( $this, 'inject_plugin_list_icon_css' ) );
        }

        private function get_brand_icon_url() {
            return plugin_dir_url( __FILE__ ) . '.branding/tabarc-icon.svg';
        }

        public function add_tools_page() {
            add_management_page(
                __( 'Capability Drift Auditor', 'wp-user-capability-drift-auditor' ),
                __( 'Capability Drift', 'wp-user-capability-drift-auditor' ),
                'manage_options',
                $this->screen_slug,
                array( $this, 'render_screen' )
            );
        }

        public function render_screen() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-user-capability-drift-auditor' ) );
            }

            $audit = $this->run_audit();

            $export_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=' . $this->export_action ),
                'wucda_export_json'
            );

            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'WP User Capability Drift Auditor', 'wp-user-capability-drift-auditor' ); ?></h1>
                <p>
                    This is an audit screen for roles and capabilities. It highlights drift, direct user overrides, and high risk permissions.
                    It does not apply fixes. If you want a button that changes permissions, install something else and then regret it later.
                </p>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
                        <?php esc_html_e( 'Export audit as JSON', 'wp-user-capability-drift-auditor' ); ?>
                    </a>
                </p>

                <h2><?php esc_html_e( 'Summary', 'wp-user-capability-drift-auditor' ); ?></h2>
                <?php $this->render_summary( $audit ); ?>

                <h2><?php esc_html_e( 'High risk capabilities held by non admins', 'wp-user-capability-drift-auditor' ); ?></h2>
                <p>
                    These are the capabilities that change the site or the codebase. In a sane world, only administrators have them.
                    In WordPress reality, sometimes an editor gets promoted and nobody cleans it up.
                </p>
                <?php $this->render_high_risk( $audit ); ?>

                <h2><?php esc_html_e( 'Users with direct capability assignments', 'wp-user-capability-drift-auditor' ); ?></h2>
                <p>
                    Direct user caps override role expectations. They are often added during emergencies and then forgotten.
                    This list shows users who have capabilities assigned directly on their account.
                </p>
                <?php $this->render_direct_user_caps( $audit ); ?>

                <h2><?php esc_html_e( 'Role drift for default WordPress roles', 'wp-user-capability-drift-auditor' ); ?></h2>
                <p>
                    This compares your current role capability sets against a baseline for standard roles.
                    It is not perfect. It is still useful. If your editor role looks like an admin role, that is your problem.
                </p>
                <?php $this->render_role_drift( $audit ); ?>

                <h2><?php esc_html_e( 'Custom roles', 'wp-user-capability-drift-auditor' ); ?></h2>
                <p>
                    Custom roles are listed for visibility. This tool does not claim to know what they should contain.
                    It will still flag if a custom role holds high risk capabilities.
                </p>
                <?php $this->render_custom_roles( $audit ); ?>

                <h2><?php esc_html_e( 'Capabilities that look orphaned', 'wp-user-capability-drift-auditor' ); ?></h2>
                <p>
                    These capabilities exist in roles or user assignments but are not part of the WordPress default role baseline.
                    Some belong to active plugins. Some belong to dead plugins. Some are typos. Treat them with suspicion.
                </p>
                <?php $this->render_orphan_caps( $audit ); ?>

                <p style="font-size:12px;opacity:0.8;margin-top:2em;">
                    <?php esc_html_e( 'Tip: test changes on staging. Capabilities are easy to add, annoying to debug, and catastrophic when wrong.', 'wp-user-capability-drift-auditor' ); ?>
                </p>
            </div>
            <?php
        }

        public function inject_plugin_list_icon_css() {
            $icon_url = esc_url( $this->get_brand_icon_url() );
            ?>
            <style>
                .wp-list-table.plugins tr[data-slug="wp-user-capability-drift-auditor"] .plugin-title strong::before {
                    content: '';
                    display: inline-block;
                    vertical-align: middle;
                    width: 18px;
                    height: 18px;
                    margin-right: 6px;
                    background-image: url('<?php echo $icon_url; ?>');
                    background-repeat: no-repeat;
                    background-size: contain;
                }
            </style>
            <?php
        }

        private function run_audit() {
            $wp_roles = wp_roles();
            if ( ! $wp_roles ) {
                return array(
                    'error' => 'wp_roles() not available',
                );
            }

            $roles = $wp_roles->roles;

            $baseline = $this->get_default_role_baseline();
            $high_risk_caps = $this->get_high_risk_caps();

            $role_drift = array();
            $custom_roles = array();
            $orphan_caps = array();

            $all_caps_seen = array();

            foreach ( $roles as $role_key => $role_data ) {
                $caps = isset( $role_data['capabilities'] ) && is_array( $role_data['capabilities'] ) ? $role_data['capabilities'] : array();

                $role_caps_true = array();
                foreach ( $caps as $cap => $enabled ) {
                    if ( $enabled ) {
                        $role_caps_true[] = $cap;
                        $all_caps_seen[ $cap ] = true;
                    }
                }

                sort( $role_caps_true );

                $is_default = isset( $baseline[ $role_key ] );

                if ( $is_default ) {
                    $base_caps = $baseline[ $role_key ];
                    sort( $base_caps );

                    $added   = array_values( array_diff( $role_caps_true, $base_caps ) );
                    $removed = array_values( array_diff( $base_caps, $role_caps_true ) );

                    $role_drift[ $role_key ] = array(
                        'name'        => $role_data['name'],
                        'added'       => $added,
                        'removed'     => $removed,
                        'caps_count'  => count( $role_caps_true ),
                        'high_risk'   => array_values( array_intersect( $role_caps_true, $high_risk_caps ) ),
                    );
                } else {
                    $custom_roles[ $role_key ] = array(
                        'name'       => $role_data['name'],
                        'caps_count' => count( $role_caps_true ),
                        'caps'       => $role_caps_true,
                        'high_risk'  => array_values( array_intersect( $role_caps_true, $high_risk_caps ) ),
                    );
                }

                // Orphan cap detection: anything not in the union of baseline sets gets collected.
                foreach ( $role_caps_true as $cap ) {
                    if ( ! $this->cap_in_baseline_union( $cap, $baseline ) ) {
                        $orphan_caps[ $cap ] = true;
                    }
                }
            }

            $users = get_users(
                array(
                    'fields' => array( 'ID', 'user_login', 'user_email' ),
                    'number' => 0,
                )
            );

            $direct_user_caps = array();
            $high_risk_non_admins = array();

            foreach ( $users as $user_obj ) {
                $user = get_user_by( 'id', $user_obj->ID );
                if ( ! $user instanceof WP_User ) {
                    continue;
                }

                $roles_for_user = is_array( $user->roles ) ? $user->roles : array();

                // User->caps contains both role flags and direct assignments.
                $raw_caps = is_array( $user->caps ) ? $user->caps : array();

                // Filter out the role keys, what remains are direct user caps.
                $direct = array();
                foreach ( $raw_caps as $cap => $enabled ) {
                    if ( isset( $roles[ $cap ] ) ) {
                        continue;
                    }
                    if ( $enabled ) {
                        $direct[] = $cap;
                        $all_caps_seen[ $cap ] = true;

                        if ( ! $this->cap_in_baseline_union( $cap, $baseline ) ) {
                            $orphan_caps[ $cap ] = true;
                        }
                    }
                }

                sort( $direct );

                if ( ! empty( $direct ) ) {
                    $direct_user_caps[] = array(
                        'ID'     => $user->ID,
                        'login'  => $user->user_login,
                        'email'  => $user->user_email,
                        'roles'  => $roles_for_user,
                        'direct' => $direct,
                    );
                }

                $is_admin = in_array( 'administrator', $roles_for_user, true );

                if ( ! $is_admin ) {
                    $effective_caps = array_keys( array_filter( $user->allcaps ) );
                    $hits = array_values( array_intersect( $effective_caps, $high_risk_caps ) );

                    if ( ! empty( $hits ) ) {
                        $high_risk_non_admins[] = array(
                            'ID'    => $user->ID,
                            'login' => $user->user_login,
                            'email' => $user->user_email,
                            'roles' => $roles_for_user,
                            'caps'  => $hits,
                        );
                    }
                }
            }

            ksort( $orphan_caps );
            $orphan_caps_list = array_keys( $orphan_caps );

            return array(
                'roles'               => $roles,
                'baseline'            => $baseline,
                'high_risk_caps'      => $high_risk_caps,
                'role_drift'          => $role_drift,
                'custom_roles'        => $custom_roles,
                'direct_user_caps'    => $direct_user_caps,
                'high_risk_non_admins'=> $high_risk_non_admins,
                'orphan_caps'         => $orphan_caps_list,
                'all_caps_seen'       => array_keys( $all_caps_seen ),
            );
        }

        public function handle_export_json() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'No.' );
            }

            check_admin_referer( 'wucda_export_json' );

            $audit = $this->run_audit();

            $payload = array(
                'generated_at' => gmdate( 'c' ),
                'site_url'     => site_url(),
                'audit'        => $audit,
            );

            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="capability-drift-audit.json"' );

            echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
            exit;
        }

        private function render_summary( $audit ) {
            if ( isset( $audit['error'] ) ) {
                echo '<p>' . esc_html( $audit['error'] ) . '</p>';
                return;
            }

            $roles_count = is_array( $audit['roles'] ) ? count( $audit['roles'] ) : 0;
            $custom_count = is_array( $audit['custom_roles'] ) ? count( $audit['custom_roles'] ) : 0;
            $direct_users = is_array( $audit['direct_user_caps'] ) ? count( $audit['direct_user_caps'] ) : 0;
            $high_risk_users = is_array( $audit['high_risk_non_admins'] ) ? count( $audit['high_risk_non_admins'] ) : 0;
            $orphan_caps = is_array( $audit['orphan_caps'] ) ? count( $audit['orphan_caps'] ) : 0;

            ?>
            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Total roles', 'wp-user-capability-drift-auditor' ); ?></th>
                        <td><?php echo esc_html( $roles_count ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Custom roles', 'wp-user-capability-drift-auditor' ); ?></th>
                        <td><?php echo esc_html( $custom_count ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Users with direct capability assignments', 'wp-user-capability-drift-auditor' ); ?></th>
                        <td><?php echo esc_html( $direct_users ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Non admin users holding high risk capabilities', 'wp-user-capability-drift-auditor' ); ?></th>
                        <td>
                            <?php
                            if ( $high_risk_users > 0 ) {
                                echo '<span style="color:#dc3232;font-weight:600;">' . esc_html( $high_risk_users ) . '</span>';
                            } else {
                                echo '<span style="color:#46b450;font-weight:600;">0</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Capabilities that look orphaned', 'wp-user-capability-drift-auditor' ); ?></th>
                        <td><?php echo esc_html( $orphan_caps ); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php
        }

        private function render_high_risk( $audit ) {
            $rows = isset( $audit['high_risk_non_admins'] ) && is_array( $audit['high_risk_non_admins'] ) ? $audit['high_risk_non_admins'] : array();

            if ( empty( $rows ) ) {
                echo '<p><span style="color:#46b450;font-weight:600;">No non admin users currently hold the high risk capability set.</span></p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>User</th><th>Roles</th><th>High risk caps</th></tr></thead><tbody>';

            foreach ( $rows as $row ) {
                $user_label = $row['login'] . ' (ID ' . (int) $row['ID'] . ')';
                $roles = ! empty( $row['roles'] ) ? implode( ', ', array_map( 'sanitize_key', $row['roles'] ) ) : '';
                $caps  = ! empty( $row['caps'] ) ? implode( ', ', array_map( 'sanitize_key', $row['caps'] ) ) : '';

                echo '<tr>';
                echo '<td>' . esc_html( $user_label ) . '<br><span style="opacity:0.7;font-size:12px;">' . esc_html( $row['email'] ) . '</span></td>';
                echo '<td><code>' . esc_html( $roles ) . '</code></td>';
                echo '<td><code style="color:#dc3232;">' . esc_html( $caps ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'If this list is not empty, take a breath and work out why these users have power that should normally be limited to administrators.', 'wp-user-capability-drift-auditor' );
            echo '</p>';
        }

        private function render_direct_user_caps( $audit ) {
            $rows = isset( $audit['direct_user_caps'] ) && is_array( $audit['direct_user_caps'] ) ? $audit['direct_user_caps'] : array();

            if ( empty( $rows ) ) {
                echo '<p>No users with direct capability assignments detected.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>User</th><th>Roles</th><th>Direct caps</th></tr></thead><tbody>';

            foreach ( $rows as $row ) {
                $user_label = $row['login'] . ' (ID ' . (int) $row['ID'] . ')';
                $roles = ! empty( $row['roles'] ) ? implode( ', ', array_map( 'sanitize_key', $row['roles'] ) ) : '';
                $caps  = ! empty( $row['direct'] ) ? implode( ', ', array_map( 'sanitize_key', $row['direct'] ) ) : '';

                echo '<tr>';
                echo '<td>' . esc_html( $user_label ) . '<br><span style="opacity:0.7;font-size:12px;">' . esc_html( $row['email'] ) . '</span></td>';
                echo '<td><code>' . esc_html( $roles ) . '</code></td>';
                echo '<td><code>' . esc_html( $caps ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        private function render_role_drift( $audit ) {
            $rows = isset( $audit['role_drift'] ) && is_array( $audit['role_drift'] ) ? $audit['role_drift'] : array();

            if ( empty( $rows ) ) {
                echo '<p>No baseline role drift data available.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Role</th><th>Added caps</th><th>Removed caps</th><th>High risk in role</th></tr></thead><tbody>';

            foreach ( $rows as $role_key => $row ) {
                $added = ! empty( $row['added'] ) ? implode( ', ', array_map( 'sanitize_key', $row['added'] ) ) : '';
                $removed = ! empty( $row['removed'] ) ? implode( ', ', array_map( 'sanitize_key', $row['removed'] ) ) : '';
                $risk = ! empty( $row['high_risk'] ) ? implode( ', ', array_map( 'sanitize_key', $row['high_risk'] ) ) : '';

                $added_display = $added ? '<code style="color:#dc3232;">' . esc_html( $added ) . '</code>' : '<span style="opacity:0.7;">none</span>';
                $removed_display = $removed ? '<code>' . esc_html( $removed ) . '</code>' : '<span style="opacity:0.7;">none</span>';
                $risk_display = $risk ? '<code style="color:#dc3232;">' . esc_html( $risk ) . '</code>' : '<span style="opacity:0.7;">none</span>';

                echo '<tr>';
                echo '<td><strong>' . esc_html( $row['name'] ) . '</strong><br><code>' . esc_html( $role_key ) . '</code></td>';
                echo '<td>' . $added_display . '</td>';
                echo '<td>' . $removed_display . '</td>';
                echo '<td>' . $risk_display . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'Added caps are the usual sign of drift. Removed caps can also matter, because they silently break workflows and lead to panic promotions.', 'wp-user-capability-drift-auditor' );
            echo '</p>';
        }

        private function render_custom_roles( $audit ) {
            $roles = isset( $audit['custom_roles'] ) && is_array( $audit['custom_roles'] ) ? $audit['custom_roles'] : array();

            if ( empty( $roles ) ) {
                echo '<p>No custom roles detected.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Role</th><th>Caps count</th><th>High risk caps</th><th>Caps sample</th></tr></thead><tbody>';

            foreach ( $roles as $role_key => $row ) {
                $risk = ! empty( $row['high_risk'] ) ? implode( ', ', array_map( 'sanitize_key', $row['high_risk'] ) ) : '';
                $risk_display = $risk ? '<code style="color:#dc3232;">' . esc_html( $risk ) . '</code>' : '<span style="opacity:0.7;">none</span>';

                $caps = isset( $row['caps'] ) && is_array( $row['caps'] ) ? $row['caps'] : array();
                $sample = array_slice( $caps, 0, 14 );
                $sample_display = ! empty( $sample ) ? implode( ', ', array_map( 'sanitize_key', $sample ) ) : '';
                if ( count( $caps ) > count( $sample ) ) {
                    $sample_display .= ', ...';
                }

                echo '<tr>';
                echo '<td><strong>' . esc_html( $row['name'] ) . '</strong><br><code>' . esc_html( $role_key ) . '</code></td>';
                echo '<td>' . esc_html( (int) $row['caps_count'] ) . '</td>';
                echo '<td>' . $risk_display . '</td>';
                echo '<td><code>' . esc_html( $sample_display ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        private function render_orphan_caps( $audit ) {
            $caps = isset( $audit['orphan_caps'] ) && is_array( $audit['orphan_caps'] ) ? $audit['orphan_caps'] : array();

            if ( empty( $caps ) ) {
                echo '<p>No orphan looking capabilities detected. Either this site is unusually clean or everything happens to match the baseline sets.</p>';
                return;
            }

            $groups = $this->group_caps_by_prefix( $caps );

            echo '<table class="widefat striped" style="max-width:1100px;">';
            echo '<thead><tr><th>Prefix</th><th>Count</th><th>Caps</th></tr></thead><tbody>';

            foreach ( $groups as $prefix => $list ) {
                $count = count( $list );
                $caps_display = implode( ', ', array_map( 'sanitize_key', $list ) );

                echo '<tr>';
                echo '<td><code>' . esc_html( $prefix ) . '</code></td>';
                echo '<td>' . esc_html( $count ) . '</td>';
                echo '<td><code>' . esc_html( $caps_display ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'If you see capabilities with a clear plugin prefix, they probably came from a plugin. If you see a cap that looks like a typo, it probably is. Yes, that happens.', 'wp-user-capability-drift-auditor' );
            echo '</p>';
        }

        private function group_caps_by_prefix( $caps ) {
            $groups = array();

            foreach ( $caps as $cap ) {
                $cap = (string) $cap;

                $prefix = 'misc';

                if ( strpos( $cap, '_' ) !== false ) {
                    $parts = explode( '_', $cap );
                    $prefix = $parts[0];
                    if ( $prefix === '' ) {
                        $prefix = 'misc';
                    }
                }

                if ( ! isset( $groups[ $prefix ] ) ) {
                    $groups[ $prefix ] = array();
                }

                $groups[ $prefix ][] = $cap;
            }

            // Sort groups by count desc, then name.
            uasort(
                $groups,
                function( $a, $b ) {
                    $ca = count( $a );
                    $cb = count( $b );
                    if ( $ca === $cb ) {
                        return 0;
                    }
                    return ( $ca > $cb ) ? -1 : 1;
                }
            );

            foreach ( $groups as $prefix => $list ) {
                sort( $list );
                $groups[ $prefix ] = $list;
            }

            return $groups;
        }

        private function cap_in_baseline_union( $cap, $baseline ) {
            foreach ( $baseline as $role_caps ) {
                if ( in_array( $cap, $role_caps, true ) ) {
                    return true;
                }
            }
            return false;
        }

        private function get_high_risk_caps() {
            // If these exist outside admins, I want to know.
            // Some sites intentionally delegate a few of these. Most do not mean to.
            return array(
                'manage_options',
                'edit_theme_options',
                'customize',
                'activate_plugins',
                'install_plugins',
                'update_plugins',
                'delete_plugins',
                'edit_plugins',
                'upload_plugins',
                'switch_themes',
                'install_themes',
                'update_themes',
                'delete_themes',
                'edit_themes',
                'edit_files',
                'edit_users',
                'create_users',
                'delete_users',
                'promote_users',
                'list_users',
                'remove_users',
                'update_core',
                'export',
                'import',
                'unfiltered_html',
                'unfiltered_upload',
            );
        }

        private function get_default_role_baseline() {
            // Baseline set for the most common default roles.
            // This is best effort and intentionally conservative.
            // WordPress core shifts over time, and plugins can add meta caps.
            // The goal is to catch obvious drift, not argue philosophy.

            // Subscriber baseline.
            $subscriber = array(
                'read',
            );

            // Contributor baseline.
            $contributor = array(
                'read',
                'edit_posts',
                'delete_posts',
            );

            // Author baseline.
            $author = array(
                'read',
                'edit_posts',
                'delete_posts',
                'publish_posts',
                'upload_files',
                'delete_published_posts',
                'edit_published_posts',
            );

            // Editor baseline.
            $editor = array(
                'read',
                'edit_posts',
                'edit_others_posts',
                'edit_published_posts',
                'publish_posts',
                'delete_posts',
                'delete_published_posts',
                'delete_others_posts',
                'manage_categories',
                'moderate_comments',
                'upload_files',
                'edit_pages',
                'edit_others_pages',
                'edit_published_pages',
                'publish_pages',
                'delete_pages',
                'delete_published_pages',
                'delete_others_pages',
                'read_private_pages',
                'read_private_posts',
            );

            // Administrator baseline.
            // This is simplified. Real admins can do lots more via meta caps and plugin additions.
            // Still, if non admins start matching this set, we have a problem.
            $administrator = array(
                'read',
                'manage_options',
                'edit_theme_options',
                'customize',
                'activate_plugins',
                'install_plugins',
                'update_plugins',
                'delete_plugins',
                'edit_plugins',
                'upload_plugins',
                'switch_themes',
                'install_themes',
                'update_themes',
                'delete_themes',
                'edit_themes',
                'edit_files',
                'edit_users',
                'create_users',
                'delete_users',
                'promote_users',
                'list_users',
                'remove_users',
                'update_core',
                'export',
                'import',
                'moderate_comments',
                'manage_categories',
                'upload_files',
                'unfiltered_html',
            );

            return array(
                'subscriber'    => $subscriber,
                'contributor'   => $contributor,
                'author'        => $author,
                'editor'        => $editor,
                'administrator' => $administrator,
            );
        }
    }

    new WP_User_Capability_Drift_Auditor();
}
