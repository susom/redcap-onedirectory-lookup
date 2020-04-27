Fields = {
    list: {},
    user: {},
    $input: null,
    map: {},
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
                console.log(name)
                //$('input[name ="'+name+'"]').after('<div><img src="'+Fields.image+'" title="Search Users"  style="margin-bottom:1px;"></div>')
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

                Fields.map = Fields.findSearchFieldMap(Fields.$input.attr('name'));

                Fields.fillInformation();
            }
        });
    },
    fillInformation: function () {
        var m = Fields.map;
        console.log(m)
        for (var key in m) {
            if (m.hasOwnProperty(key)) {
                $('input[name ="' + m[key] + '"]').val(Fields.user[key])
            }
        }
    },

    findSearchFieldMap: function (name) {
        var l = Fields.list;
        for (i = 0; i < l.length; i++) {
            if (Fields.list[i]['search-field'] == name) {
                return Fields.list[i]['map'];
            }
        }
        return false;
    }
}
//run function once load is complete.
window.onload = function () {
    Fields.init();
}