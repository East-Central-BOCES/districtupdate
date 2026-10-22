// SPDX-License-Identifier: GPL-3.0-or-later
define(['jquery', 'core/config'], function($, Config) {
    return {
        transport: function(selector, query, success, failure) {
            if (query.trim().length < 2) {
                success([]);
                return;
            }
            $.ajax({
                url: Config.wwwroot + '/local/districtupdate/search.php',
                method: 'POST',
                dataType: 'json',
                data: {sesskey: Config.sesskey, query: query}
            }).done(function(results) {
                if (Array.isArray(results)) {
                    success(results);
                } else {
                    failure(new Error('Unable to search reviewers. Refresh the page and try again.'));
                }
            }).fail(function() {
                failure(new Error('Unable to search reviewers. Refresh the page and try again.'));
            });
        },
        processResults: function(selector, results) {
            return results.map(function(user) {
                return {value: user.id, label: $('<span>').text(user.label).html()};
            });
        }
    };
});
