<?php

return [
    'common' => [
        'role' => 'Role',
        'roles' => 'Roles',
        'permissions' => 'Permissions',
        'linked_profile' => 'Linked profile',
        'login_identity' => 'Login identity',
        'generated_password' => 'Generated password',
    ],
    'profile_accounts' => [
        'title' => 'Account access',
        'description' => 'Manage the linked login account separately from the profile details.',
        'fields' => [
            'username' => 'Username',
            'email' => 'Email',
            'password' => 'New password',
            'issued_password' => 'Last issued password',
            'is_active' => 'Active login account',
        ],
        'sections' => [
            'identity' => 'Login identity',
            'password' => 'Password handoff',
        ],
        'actions' => [
            'manage' => 'Account access',
            'save' => 'Save account',
            'generate_password' => 'Generate password',
        ],
        'help' => [
            'username' => 'Leave blank to keep the current username or auto-generate one for a new account.',
            'email' => 'Leave blank to keep the current email or auto-generate a system email for a new account.',
            'password' => 'Enter a new password only when you want to reset it for the user.',
            'issued_password' => 'Only the last password issued from this admin panel can be shown here. If the user changed it later, issue a new one.',
        ],
        'messages' => [
            'credentials' => 'Login: :login | Password: :password',
            'saved' => 'Account access updated successfully.',
        ],
        'empty' => [
            'issued_password' => 'No issued password stored yet.',
        ],
    ],
    'users' => [
        'title' => 'Users',
        'subtitle' => 'Manage login accounts, assign roles, and add direct permissions beyond the user’s main role.',
        'stats' => [
            'total' => 'Visible users',
            'active' => 'Active accounts',
            'linked_profiles' => 'Linked profiles',
        ],
        'form' => [
            'create' => 'New user',
            'edit' => 'Edit user',
            'save_create' => 'Create user',
            'save_update' => 'Update user',
            'cancel' => 'Cancel',
        ],
        'sections' => [
            'identity' => 'Login identity',
            'access' => 'Access package',
            'scope' => 'Scope overrides',
        ],
        'help' => [
            'password' => 'When creating a user, leave username, email, or password blank to generate them automatically. When editing, blank password keeps the current password unchanged.',
            'roles' => 'Roles define the base access package for this account.',
            'permissions' => 'Direct permissions extend this user without changing the assigned roles.',
            'scope' => 'Use scope overrides only for exceptions. Normal teacher, parent, and student visibility should come from their linked profile and role.',
        ],
        'messages' => [
            'created' => 'User created successfully.',
            'updated' => 'User updated successfully.',
            'deleted' => 'User deleted successfully.',
        ],
        'errors' => [
            'delete_self' => 'You cannot delete the account you are currently using.',
            'delete_linked_profile' => 'This account is linked to a profile and cannot be deleted from the user manager.',
        ],
        'fields' => [
            'name' => 'Full name',
            'username' => 'Username',
            'email' => 'Email',
            'phone' => 'Phone',
            'password' => 'Password',
            'is_active' => 'Active account',
            'roles' => 'Assigned roles',
            'permissions' => 'Direct permissions',
        ],
        'filters' => [
            'role' => 'Role',
            'all_roles' => 'All roles',
        ],
        'scopes' => [
            'title' => 'Scope overrides',
            'help' => 'Use extra scope assignments to extend a specific user beyond their normal role-linked data without changing the role defaults.',
            'groups' => 'Extra groups',
            'students' => 'Extra students',
            'teachers' => 'Extra teachers',
            'parents' => 'Extra parents',
            'empty' => 'No records available.',
        ],
        'table' => [
            'empty' => 'No users yet.',
            'headers' => [
                'user' => 'User',
                'roles' => 'Roles',
                'permissions' => 'Direct permissions',
                'profile' => 'Profile',
                'status' => 'Status',
                'actions' => 'Actions',
            ],
            'none' => 'No direct permissions',
        ],
    ],
    'roles' => [
        'title' => 'Role Permissions',
        'subtitle' => 'Define the default permission set for each role. Users can still receive extra direct permissions individually.',
        'stats' => [
            'total' => 'Visible roles',
            'system' => 'System roles',
            'custom' => 'Custom roles',
        ],
        'actions' => [
            'create' => 'Create role',
            'edit' => 'Edit role',
            'permissions' => 'Permissions',
            'delete' => 'Delete role',
            'select' => 'Select',
            'save' => 'Save permissions',
            'cancel' => 'Cancel',
        ],
        'fields' => [
            'role' => 'Role',
            'name' => 'Role name',
            'clone_from' => 'Clone permissions from',
            'search' => 'Search roles',
            'permission_search' => 'Search permissions',
        ],
        'messages' => [
            'saved' => 'Role permissions updated successfully.',
            'created' => 'Role created successfully.',
            'updated' => 'Role updated successfully.',
            'deleted' => 'Role deleted successfully.',
        ],
        'help' => [
            'super_admin' => 'Super Admin bypasses normal permission checks and always has full access.',
            'system_role' => 'System roles are built into the workflow. You can edit their permissions, but you cannot rename or delete them.',
            'custom_role' => 'Custom roles are flexible permission packages. They can be renamed, deleted, and assigned without changing actor-based scope rules.',
            'clone_from' => 'Optional. Start from an existing role permission set instead of selecting every permission manually.',
            'machine_name' => 'Saved internally as `:name`.',
        ],
        'errors' => [
            'protected' => 'This role is protected and cannot be renamed or deleted.',
            'delete_linked' => 'This role cannot be deleted while users are still assigned to it.',
        ],
        'options' => [
            'none' => 'Start empty',
        ],
        'table' => [
            'headers' => [
                'role' => 'Role',
                'users' => 'Users',
                'permissions' => 'Permissions',
                'type' => 'Type',
                'actions' => 'Actions',
            ],
            'empty' => 'No roles found for the current search.',
        ],
        'types' => [
            'system' => 'System role',
            'custom' => 'Custom role',
        ],
        'editor' => [
            'title' => 'Permission editor',
            'subtitle' => 'Select a role to define its default permission package.',
            'empty' => 'Choose a role from the table to edit its permission set.',
            'counts' => ':permissions permissions across :users users.',
        ],
    ],
    'login' => [
        'title' => 'Log in to your account',
        'description' => 'Enter your email, username, or phone and your password below to log in.',
        'identifier' => 'Email, username, or phone',
        'placeholder' => 'email@example.com / username / 09xxxxxxxx',
        'inactive' => 'This account is currently inactive.',
    ],
];
