Fields = {
    list: {},
    user: {},
    $input: null,
    map: {},
    alert: false,
    cancel: false,
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
                $('input[name ="' + name + '"]').after('<div><img src="' + Fields.image + '" title="Search Users"  style="margin-bottom:1px;"></div>')
            }
        }
    },
    addInputToList: function (index, name) {
        Fields.list[index]['search-input'] = $('input[name ="' + name + '"]');
    },
    makeInputAutoComplete: function (name) {
        $('input[name ="' + name + '"]').autocomplete({
            source: $("#get-users-ajax-url").val(),
            minLength: 2,
            select: function (event, ui) {
                Fields.user = ui.item['array'];
                Fields.$input = $(event.target)

                Fields.findSearchFieldMap(Fields.$input.attr('name'));

                return Fields.fillInformation();
            }
        });
    },
    fillInformation: function () {
        var m = Fields.map;

        for (var key in m) {
            if (m.hasOwnProperty(key)) {
                if (Fields.alert && $('[name ="' + m[key] + '"]').val() !== '' && !Fields.cancel) {
                    if (confirm("Input has data on it are you sure you want to override existing data? ")) {
                        $('[name ="' + m[key] + '"]').val(Fields.user[key]);
                        console.log(m[key])
                        // once we accept one time then the same action will be applied to all inputs
                        Fields.alert = false;
                    } else {
                        return false;
                    }
                } else if (!Fields.cancel) {
                    $('[name ="' + m[key] + '"]').val(Fields.user[key]);
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