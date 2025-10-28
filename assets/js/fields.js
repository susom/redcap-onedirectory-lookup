Fields = {
    list: {},
    user: {},
    $input: null,
    map: {},
    alert: false,
    cancel: false,
    _acState: {}, // per-input autocomplete pagination state
    // Inline SVG loader (32x32), used while profile image is loading
    loadingImage: 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid"><circle cx="50" cy="50" r="32" stroke-width="10" stroke="#999" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" values="0 50 50;360 50 50" keyTimes="0;1"></animateTransform></circle></svg>',
    init: function () {
        Fields.searchPage();
    },
    searchPage: function () {
        var l = Fields.list
        for (i = 0; i < l.length; i++) {
            var name = Fields.list[i]['search-field'];
            if ($('input[name ="' + name + '"]').length) {
                Fields.addInputToList(i, name);
                Fields.makeInputAutoComplete(name);
                $('input[name ="' + name + '"]').after('<div><img src="' + Fields.image + '" title="Search Users"  style="margin-bottom:1px;"></div><div class="space"></div>')
            }
        }
    },
    addInputToList: function (index, name) {
        Fields.list[index]['search-input'] = $('input[name ="' + name + '"]');
    },
    makeInputAutoComplete: function (name) {
        $('input[name ="' + name + '"]').autocomplete({
            source: function (request, response) {
                var term = request.term;
                $.getJSON(Fields.ajaxUrl, { term: term })
                    .done(function (data) {
                        var items = [];
                        // Unwrap items
                        if (data && Array.isArray(data.preview)) {
                            items = data.preview;
                        } else if (Array.isArray(data)) {
                            items = data;
                        } else if (data && data.users && Array.isArray(data.users)) {
                            // Some APIs return users array directly
                            items = data.users;
                        }

                        // Detect pagination tokens/links from common shapes
                        var nextLink = data && (data.nextLink || data.next || data['@odata.nextLink'] || (data.paging && data.paging.next));
                        var nextToken = data && (data.nextToken || data.next_page_token || data.skiptoken || data.$skiptoken);
                        var hasMore = !!(nextLink || nextToken);

                        // Store state per-input
                        Fields._acState[name] = {
                            term: term,
                            hasMore: hasMore,
                            nextLink: nextLink || null,
                            nextToken: nextToken || null,
                            loading: false
                        };

                        response(items);
                    })
                    .fail(function () {
                        Fields._acState[name] = { term: term, hasMore: false, nextLink: null, nextToken: null, loading: false };
                        response([]);
                    });
            },
            minLength: 2,
            select: function (event, ui) {
                Fields.user = ui.item['array'] || ui.item; // prefer full object if provided
                Fields.$input = $(event.target);
                Fields.findSearchFieldMap(Fields.$input.attr('name'));
                Fields.fillInformation();
            }
        });
        // When menu opens, attach scroll-to-end pagination
        $('input[name ="' + name + '"]').on('autocompleteopen', function () {
            var inst = $(this).autocomplete('instance');
            var $ul = inst.menu.element;
            var state = Fields._acState[name] || { hasMore: false };

            // Avoid multiple bindings
            $ul.off('scroll.autopage').on('scroll.autopage', function () {
                if (!state.hasMore || state.loading) return;
                var nearBottom = $ul.scrollTop() + $ul.innerHeight() >= $ul[0].scrollHeight - 4;
                if (!nearBottom) return;

                // Show a lightweight loading row
                state.loading = true;
                var $loading = $('<li class="ui-autocomplete-loading-row">Loading more…</li>').appendTo($ul);

                var appendItems = function (data) {
                    var more = [];
                    if (data && Array.isArray(data.preview)) more = data.preview;
                    else if (Array.isArray(data)) more = data;
                    else if (data && data.users && Array.isArray(data.users)) more = data.users;

                    // Update state with new pagination info
                    state.nextLink = data && (data.nextLink || data.next || data['@odata.nextLink'] || (data.paging && data.paging.next)) || null;
                    state.nextToken = data && (data.nextToken || data.next_page_token || data.skiptoken || data.$skiptoken) || null;
                    state.hasMore = !!(state.nextLink || state.nextToken);

                    // Append new items using jQuery UI internal helper
                    more.forEach(function (it) { inst._renderItemData($ul, it); });
                    inst.menu.refresh();

                    $loading.remove();
                    state.loading = false;

                    if (!state.hasMore) {
                        $('<li class="ui-autocomplete-no-more">No more users</li>').appendTo($ul);
                    }
                };

                // Always call backend with term + next_page (Graph next link or token)
                var nextPageParam = state.nextLink || state.nextToken || null;
                if (nextPageParam) {
                    $.getJSON(Fields.ajaxUrl, { term: state.term, next_page: nextPageParam })
                        .done(appendItems)
                        .fail(function () { $loading.remove(); state.loading = false; state.hasMore = false; $('<li class="ui-autocomplete-no-more">No more users</li>').appendTo($ul); });
                } else {
                    $loading.remove();
                    state.loading = false;
                    state.hasMore = false;
                    $('<li class="ui-autocomplete-no-more">No more users</li>').appendTo($ul);
                }
            });
        });
        $('input[name ="' + name + '"]').autocomplete("instance")._renderItem = function (ul, item) {
            // item is one entry from preview (URL image expected, not base64)
            var intendedSrc = item.image || Fields.image || '';

            // If the backend photo endpoint supports companyName, append it
            var companyName = (item && item.array && item.array.companyName) || item.companyName || '';
            if (intendedSrc && companyName) {
                intendedSrc = Fields.addQueryParam(intendedSrc, 'companyName', companyName);
            }

            // Thumbnail container with fixed size to avoid layout shifts
            var $thumb = $('<div>')
                .css({ width: '32px', height: '32px', position: 'relative', marginRight: '8px', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' });

            // Bootstrap spinner placeholder (ensure bootstrap.css is loaded in the page)
            var $spinner = $('<div class="spinner-border spinner-border-sm" role="status">')
                .css({ width: '24px', height: '24px', borderWidth: '2px' });

            // Real image element, hidden until loaded
            var $img = $('<img>')
                .attr('alt', 'user')
                .css({ width: '32px', height: '32px', display: 'none' });

            $thumb.append($spinner).append($img);

            // Preload actual image to avoid layout shift
            if (intendedSrc) {
                var preload = new Image();
                preload.onload = function () {
                    $img.attr('src', intendedSrc).show();
                    $spinner.hide();
                };
                preload.onerror = function () {
                    // fallback to default static image if load fails
                    if (Fields.image) {
                        $img.attr('src', Fields.image).show();
                        $spinner.hide();
                    } else {
                        // keep spinner if no fallback image
                    }
                };
                preload.src = intendedSrc;
            }

            var $li = $("<li>")
                .css({ display: 'flex', alignItems: 'center', padding: '4px' })
                .append(
                    $("<div>")
                        .css({ display: 'flex', alignItems: 'center', width: '100%' })
                        .append($thumb)
                        .append(
                            $("<div>")
                                .append(
                                    $("<span>")
                                        .addClass("user_name")
                                        .text(item.label || "")
                                )
                                .append("<br/>")
                                .append(
                                    $("<span>")
                                        .addClass("user_title")
                                        .text(item.title || "")
                                )
                        )
                );

            return $li.appendTo(ul);
        };
        // if value already saved search for that value on focus
        $('input[name ="' + name + '"]').focus(function (event, ui) {
            if ($(this).val() != '') {
                $(this).autocomplete('search', $(this).val())
            }
        });
    },

    // Helper: safely read nested values like "manager.id" or "manager.userPrincipalName"
    deepGet: function (obj, path) {
        if (!obj || !path) return undefined;
        // support bracket or dot notation: a.b[0].c -> a.b.0.c
        var clean = String(path).replace(/\[(\w+)\]/g, '.$1').replace(/^\./, '');
        var parts = clean.split('.');
        var cur = obj;
        for (var i = 0; i < parts.length; i++) {
            if (cur == null) return undefined;
            var k = parts[i];
            if (Object.prototype.hasOwnProperty.call(cur, k)) {
                cur = cur[k];
            } else {
                return undefined;
            }
        }
        return cur;
    },

    // Helper: turn arrays/objects into form-friendly strings
    normalizeValue: function (val) {
        if (val == null) return '';
        if (Array.isArray(val)) {
            // join primitives; stringify objects
            return val.map(function (v) {
                if (v == null) return '';
                if (typeof v === 'object') return JSON.stringify(v);
                return String(v);
            }).join(', ');
        }
        if (typeof val === 'object') {
            // common case: manager object -> prefer displayName/mail/upn if present
            var prefer = val.displayName || val.mail || val.userPrincipalName || val.id;
            return prefer ? String(prefer) : JSON.stringify(val);
        }
        return String(val);
    },

    // Helper: append a query parameter to a URL safely
    addQueryParam: function (url, key, value) {
        try {
            if (!url || !key || value == null) return url;
            var hasQ = url.indexOf('?') !== -1;
            var sep = hasQ ? '&' : '?';
            return url + sep + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
        } catch (e) {
            return url; // on any parsing error, return original URL
        }
    },

    fillInformation: function () {
        var m = Fields.map;

        for (var key in m) {
            if (m.hasOwnProperty(key)) {
                if (Fields.alert && $('[name ="' + m[key] + '"]').val() !== '' && !Fields.cancel) {
                    if (confirm("Input has data on it are you sure you want to override existing data? ")) {
                        var val = Fields.normalizeValue(Fields.deepGet(Fields.user, key));
                        $('[name ="' + m[key] + '"]').val(val);
                        console.log(m[key])
                        // once we accept one time then the same action will be applied to all inputs
                        Fields.alert = false;
                    } else {
                        return false;
                    }
                } else if (!Fields.cancel) {
                    var val2 = Fields.normalizeValue(Fields.deepGet(Fields.user, key));
                    $('[name ="' + m[key] + '"]').val(val2);
                }

            }
        }
        return true;
    },

    findSearchFieldMap: function (name) {
        var l = Fields.list;
        for (i = 0; i < l.length; i++) {
            if (Fields.list[i]['search-field'] == name) {
                Fields.map = Fields.list[i]['map'];
                Fields.alert = Fields.list[i]['alert-if-exist'];
            }
        }
        return false;
    }
}
//run function once load is complete.
window.onload = function () {
    Fields.init();
}
