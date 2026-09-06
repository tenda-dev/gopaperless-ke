Feature: search
  # SECURITY REGRESSION TEST (privacy/enumeration fix):
  # Previously, this endpoint let ANY authenticated requester discover ANY
  # other account on the instance, with no relationship between them
  # (searcher has never shared a document with the target). That generic
  # directory search (Nextcloud core's UserPlugin, dispatched via
  # IShare::TYPE_USER) has been removed from ShareTypeResolver. An unrelated
  # account must no longer be discoverable this way, by exact OR partial
  # username. NOT executed against a live instance in this change — verify
  # in CI before relying on it.
  Scenario: Search account by specific unrelated user returns nothing
    Given as user "admin"
    And user "search-signer1" exists
    And user "search-signer2" exists
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=search-signer1"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                   | value |
      | (jq).ocs.data\|length | 0     |

  Scenario: Search account by multiple unrelated users returns nothing
    Given as user "admin"
    And user "search-signer1" exists
    And user "search-signer2" exists
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=search-signer"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                   | value |
      | (jq).ocs.data\|length | 0     |


  # NOTE: originally the searcher stayed "admin" while searching for a
  # DIFFERENT account ("can-find-myself") — despite the scenario's title,
  # that was actually an unrelated-user generic-search test, not a genuine
  # self-search test, and it only passed because of the now-removed
  # IShare::TYPE_USER directory search. Corrected here so the searcher and
  # the target are the same user, which is what "self-identification"
  # (a required-preserved workflow) actually means; this path goes through
  # ResultEnricher::addHerselfAccount, which is untouched by this change.
  # NOT executed against a live instance in this change — verify in CI.
  Scenario: Search account by herself with partial name search
    Given as user "admin"
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"account","enabled":true}] |
    And user "can-find-myself" exists
    And run the command "group:adduser admin can-find-myself" with result code 0
    And set the email of user "can-find-myself" to "my@email.tld"
    When as user "can-find-myself"
    And sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=can-"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                          | value                       |
      | (jq).ocs.data\|length        | 1                           |
      | (jq).ocs.data[0].identify    | can-find-myself             |
      | (jq).ocs.data[0].isNoUser    | false                       |
      | (jq).ocs.data[0].displayName | can-find-myself-displayname |
      | (jq).ocs.data[0].subname     | my@email.tld                |
      | (jq).ocs.data[0].iconName    | account                     |
      | (jq).ocs.data[0].method      | account                     |

  Scenario: Search account by herself without permission to identify by account
    Given as user "admin"
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true}] |
    Given user "cant-find-myself" exists
    And run the command "group:adduser admin cant-find-myself" with result code 0
    And set the display name of user "cant-find-myself" to "Temporary Name"
    And set the email of user "cant-find-myself" to "my@email.tld"
    When as user "cant-find-myself"
    And sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=cant-find-myself"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                   | value |
      | (jq).ocs.data\|length | 0     |

  Scenario: Search account by herself with permission to identify by account
    Given as user "admin"
    And set the email of user "admin" to "admin@email.tld"
    And set the display name of user "admin" to "admin"
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=admin"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                          | value           |
      | (jq).ocs.data\|length        | 1               |
      | (jq).ocs.data[0].identify    | admin           |
      | (jq).ocs.data[0].isNoUser    | false           |
      | (jq).ocs.data[0].displayName | admin           |
      | (jq).ocs.data[0].subname     | admin@email.tld |
      | (jq).ocs.data[0].iconName    | account         |
      | (jq).ocs.data[0].method      | account         |

  Scenario: Search account by herself without permission to identify by email
    Given as user "admin"
    And set the email of user "admin" to "admin@email.tld"
    And set the display name of user "admin" to "admin"
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=admin@email.tld"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                     | value   |
      | (jq).ocs.data[0].method | account |

  Scenario: Search account by herself with permission to identify by email
    Given as user "admin"
    And set the email of user "admin" to "admin@email.tld"
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=admin@email.tld"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                          | value           |
      | (jq).ocs.data\|length        | 1               |
      | (jq).ocs.data[0].identify    | admin@email.tld |
      | (jq).ocs.data[0].isNoUser    | true            |
      | (jq).ocs.data[0].displayName | admin           |
      | (jq).ocs.data[0].subname     | admin@email.tld |
      | (jq).ocs.data[0].iconName    | email           |
      | (jq).ocs.data[0].method      | email           |

  # SECURITY REGRESSION TEST (privacy/enumeration fix):
  # These three scenarios previously found an UNRELATED account (admin has
  # no relationship with "notification-*") by exact username, via the same
  # now-removed generic directory search as the scenarios above. That
  # specific query shape (bare username, no prior relationship, no exact
  # email) is no longer expected to return a result — see "Search account by
  # specific unrelated user returns nothing" above for why.
  #
  # The acceptsEmailNotifications computation itself
  # (ResultEnricher::addEmailNotificationPreference) is NOT changed by this
  # fix and still runs for any 'account'-method result, however it is
  # obtained (known signer, exact-email resolution, or self). Re-covering it
  # end-to-end needs a scenario built around one of those reachable paths
  # (e.g. exact-email lookup with the email identify method enabled, or a
  # known-signer scenario) — deliberately NOT authored here since it could
  # not be verified by an actual test run in this environment; left as a
  # follow-up rather than guessed at. In the meantime these three scenarios
  # only assert the enumeration protection holds for this query shape.
  # NOT executed against a live instance in this change — verify in CI.
  Scenario: Search account for an unrelated user by exact username returns nothing (was: acceptsEmailNotifications true)
    Given as user "admin"
    And user "notification-enabled" exists
    And set the email of user "notification-enabled" to "enabled@test.com"
    And run the command "config:app:set activity notify_email_libresign_file_to_sign --value=1" with result code 0
    And run the command "user:setting notification-enabled activity notify_email_libresign_file_to_sign 1" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=notification-enabled"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                   | value |
      | (jq).ocs.data\|length | 0     |

  Scenario: Search account for an unrelated user by exact username returns nothing (was: acceptsEmailNotifications false, user disabled)
    Given as user "admin"
    And user "notification-disabled" exists
    And set the email of user "notification-disabled" to "disabled@test.com"
    And run the command "config:app:set activity notify_email_libresign_file_to_sign --value=1" with result code 0
    And run the command "user:setting notification-disabled activity notify_email_libresign_file_to_sign 0" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=notification-disabled"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                   | value |
      | (jq).ocs.data\|length | 0     |

  Scenario: Search account for an unrelated user by exact username returns nothing (was: acceptsEmailNotifications false, global setting disabled)
    Given as user "admin"
    And user "notification-global-off" exists
    And set the email of user "notification-global-off" to "globaloff@test.com"
    And run the command "config:app:set activity notify_email_libresign_file_to_sign --value=0" with result code 0
    And run the command "user:setting notification-global-off activity notify_email_libresign_file_to_sign 1" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=notification-global-off"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                   | value |
      | (jq).ocs.data\|length | 0     |
