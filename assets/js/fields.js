Fields = {
    list: {},
    user: {},
    $input: null,
    map: {},
    alert: false,
    cancel: false,
    SuImage: '',
    SoMImage: '',
    _acState: {}, // per-input autocomplete pagination state
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
            // item is one entry from preview (URL image expected, points to get_user_photo)
            var companyName = (item && item.array && item.array.companyName) || item.companyName || '';
            var intendedSrc = item.image || '';
            // Append companyName so backend can pick the proper fallback/branding if needed
            if (intendedSrc && companyName) {
                intendedSrc = Fields.addQueryParam(intendedSrc, 'companyName', companyName);
            }
            // Choose default image based on company
            var defaultImg = (companyName === 'Stanford University') ? (Fields.SuImage || Fields.SoMImage || Fields.image || '')
                                                                    : (Fields.SoMImage || Fields.SuImage || Fields.image || '');

            // Thumbnail container with fixed size to avoid layout shifts
            var $thumb = $('<div>')
                .css({ width: '32px', height: '32px', position: 'relative', marginRight: '8px', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' });

            // Start with default image visible
            var $img = $('<img>')
                .attr('alt', 'user')
                .attr('src', defaultImg)
                .css({ width: '32px', height: '32px', display: 'block' });

            $thumb.append($img);

            // Preload actual profile image from backend, then swap in
            if (intendedSrc) {
                var preload = new Image();
                preload.onload = function () {
                    $img.attr('src', intendedSrc);
                };
                preload.onerror = function () {
                    // keep default image if load fails
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
