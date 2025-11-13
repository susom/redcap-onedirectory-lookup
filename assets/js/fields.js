Fields = {
    list: {},
    user: {},
    $input: null,
    map: {},
    alert: false,
    cancel: false,
    SuImage: '',
    SoMImage: '',
    _imgCache: {}, // cache for preloaded images (brand + profiles)
    _acState: {}, // per-input autocomplete pagination state
    init: async function () {
        try {
            // Persist brand logos as data URLs in localStorage to bypass server no-store headers
            if (Fields.SuImage && Fields.SoMImage && window.fetch) {
                await Fields.persistBrandImages();
            }
        } catch (e) {
            // Ignore persistence errors and continue with normal preload
        }
        Fields.preloadBrandImages();
        Fields.searchPage();
    },
    // Persist SU/SoM logos to localStorage as data URLs so they survive across page loads
    persistBrandImages: async function () {
        // Bump these version keys when you change the actual image file to invalidate cache
        var suKey = 'logo_su_v1';
        var somKey = 'logo_som_v1';

        // Helper: fetch a URL and convert to data URL
        async function toDataUrl(url) {
            // Use same-origin credentials; cache:'no-store' ensures we get the bytes even if HTTP caching is disabled
            var resp = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if (!resp.ok) throw new Error('Failed to fetch image: ' + url);
            var blob = await resp.blob();
            return await new Promise(function (resolve, reject) {
                var fr = new FileReader();
                fr.onload = function () { resolve(fr.result); };   // data:image/...;base64,....
                fr.onerror = reject;
                fr.readAsDataURL(blob);
            });
        }

        try {
            // Guard against environments without localStorage
            if (!window.localStorage) throw new Error('no localStorage');

            var suData = localStorage.getItem(suKey);
            if (!suData && Fields.SuImage) {
                suData = await toDataUrl(Fields.SuImage);
                localStorage.setItem(suKey, suData);
            }
            if (suData) {
                Fields.SuImage = suData; // replace URL with data URL
            }

            var somData = localStorage.getItem(somKey);
            if (!somData && Fields.SoMImage) {
                somData = await toDataUrl(Fields.SoMImage);
                localStorage.setItem(somKey, somData);
            }
            if (somData) {
                Fields.SoMImage = somData; // replace URL with data URL
            }
        } catch (e) {
            // If persistence fails, leave Fields.SuImage/SoMImage as normal URLs
        }
    },
    searchPage: function () {
        var l = Fields.list
        for (i = 0; i < l.length; i++) {
            var name = Fields.list[i]['search-field'];
            if ($('input[name ="' + name + '"]').length) {
                // -- Affiliation enforcement per-instance --
                var enforce = Fields.list[i]['enforce-affiliation'];
                var source  = Fields.list[i]['affiliation-enforcement-source'];
                var emVal   = Fields.list[i]['affiliation-em-value'];
                var surveyField = Fields.list[i]['affiliation-survey-field'];

                // Initialize holder for per-instance companyName
                Fields.list[i].companyName = Fields.list[i].companyName || '';

                if (enforce === 'yes') {
                    if (source === 'em' && emVal) {
                        // Fixed company from EM settings
                        Fields.list[i].companyName = emVal;
                    } else if (source === 'survey' && surveyField) {
                        // Attach listeners to survey field (dropdown or radio group).
                        // For radio, REDCap-style name will be like [FIELD_NAME]___radio; for dropdown it's just [FIELD_NAME].
                        (function (instIndex, fieldName) {
                            var $els = $("select[name='" + fieldName + "'], input[type='radio'][name^='" + fieldName + "___']");
                            if (!$els.length) return;

                            var read = function () {
                                var v = '';
                                if ($els.length > 1) {
                                    // Radio group: use checked value (expected to be 1,2,3 etc.)
                                    var $checked = $els.filter(':checked');
                                    if ($checked.length) v = $checked.first().val() || '';
                                } else {
                                    // Single dropdown or single input: take its value directly (1,2,3)
                                    v = $els.val() || '';
                                }
                                // Pass the raw affiliation code to backend; it will map 1/2/3 -> label.
                                Fields.list[instIndex].companyName = v;
                            };

                            $els.off('.affCompany').on('change.affCompany input.affCompany keyup.affCompany', read);
                            // initial read
                            read();
                        })(i, surveyField);
                    }
                }
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
                // Lookup per-instance companyName by search-field name
                var inst = null;
                for (var j = 0; j < Fields.list.length; j++) {
                    if (Fields.list[j]['search-field'] === name) { inst = Fields.list[j]; break; }
                }
                var companyName = (inst && inst.companyName) || '';
                var term = request.term;
                $.getJSON(Fields.ajaxUrl, { term: term, companyName: companyName })
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

                        // If no items were returned, show a non-selectable "No results" row
                        if (!items || items.length === 0) {
                            items = [{
                                label: 'No results found',
                                value: term,
                                noResults: true
                            }];
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
                // Ignore the synthetic "no results" item
                if (ui.item && ui.item.noResults) {
                    event.preventDefault();
                    return false;
                }
                // Normalize selected user object (backend may wrap full user in item.array)
                Fields.user = ui.item['array'] || ui.item;
                Fields.$input = $(event.target);
                Fields.findSearchFieldMap(Fields.$input.attr('name'));

                var user = Fields.user;
                // managerURL may come from the backend as part of the user payload
                var managerUrl = user.managerURL || user.managerUrl || (user.array && (user.array.managerURL || user.array.managerUrl));


                // If we have a manager URL, fetch manager info before filling fields
                if (managerUrl) {

                    $.getJSON(managerUrl)
                        .done(function (data) {
                            // Append returned manager data under `manager` on the same user object.
                            // If backend wraps it as {manager: {...}} use that, otherwise use the payload as-is.
                            if (data && data.manager) {
                                user.manager = data.manager;
                            } else {
                                user.manager = data;
                            }
                            Fields.user = user;
                            Fields.fillInformation();
                        })
                        .fail(function () {
                            // On failure, remove spinner and fall back to filling with the base user data only
                            removeSpinner();
                            Fields.fillInformation();
                        });
                } else {
                    // No managerURL defined; just fill mapped fields from the base user object
                    Fields.fillInformation();
                }
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
                // Lookup per-instance companyName by search-field name
                var instObj = null;
                for (var j = 0; j < Fields.list.length; j++) {
                    if (Fields.list[j]['search-field'] === name) { instObj = Fields.list[j]; break; }
                }
                var companyName = (instObj && instObj.companyName) || '';
                if (nextPageParam) {
                    $.getJSON(Fields.ajaxUrl, { term: state.term, next_page: nextPageParam, companyName: companyName })
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
            // Render a simple row for the synthetic "no results" item
            if (item && item.noResults) {
                var $liNo = $('<li>')
                    .addClass('ui-autocomplete-no-results')
                    .text(item.label || 'No results found')
                    .css({ padding: '4px 8px', color: '#666' });
                return $liNo.appendTo(ul);
            }
            // item is one entry from preview (URL image expected, points to get_user_photo)
            var companyName = (item && item.array && item.array.companyName) || item.companyName || '';
            var intendedSrc = item.image || '';
            // Append companyName so backend can pick the proper fallback/branding if needed
            if (intendedSrc && companyName) {
                intendedSrc = Fields.addQueryParam(intendedSrc, 'companyName', companyName);
            }
            // Choose default image based on company and prefer preloaded cache
            var defaultKey = (companyName === 'Stanford University') ? 'SuImage' : 'SoMImage';
            var defaultUrl = Fields[defaultKey] || Fields.image || '';
            var cachedDefault = Fields._imgCache[defaultKey];

            // Thumbnail container with fixed size to avoid layout shifts
            var $thumb = $('<div>')
                .css({ width: '24px', height: '32px', position: 'relative', marginRight: '8px', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' });

            // Start with default image visible (use cached src if available)
            var $img = $('<img>')
                .attr('alt', 'user')
                .attr('src', cachedDefault ? cachedDefault.src : defaultUrl)
                .css({ width: '32px', height: '32px', display: 'block' });

            $thumb.append($img);

            // Preload actual profile image from backend (and cache by URL), then swap in
            if (intendedSrc) {
                if (Fields._imgCache[intendedSrc]) {
                    // already cached, swap immediately
                    $img.attr('src', Fields._imgCache[intendedSrc].src);
                } else {
                    Fields.preloadUrl(intendedSrc, function () {
                        $img.attr('src', intendedSrc);
                    });
                }
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

    // Preload the default Stanford brand images (SU / SoM) into cache
    preloadBrandImages: function () {
        try {
            ['SuImage', 'SoMImage'].forEach(function (key) {
                var url = Fields[key];
                if (typeof url === 'string' && url.length > 0) {
                    var img = new Image();
                    img.src = url;
                    Fields._imgCache[key] = img; // keep a reference to prevent GC
                }
            });
        } catch (e) {
            // no-op
        }
    },

    // Preload any arbitrary URL and memoize it in _imgCache by URL string
    preloadUrl: function (url, onload) {
        if (!url || typeof url !== 'string') return null;
        if (Fields._imgCache[url]) {
            if (typeof onload === 'function') onload(Fields._imgCache[url]);
            return Fields._imgCache[url];
        }
        var img = new Image();
        img.onload = function () {
            Fields._imgCache[url] = img;
            if (typeof onload === 'function') onload(img);
        };
        img.onerror = function () {
            // leave uncached on error
        };
        img.src = url;
        return img;
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

