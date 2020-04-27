Fields = {
    list: {},
    init: function () {
        console.log(Fields.list);
    }
}
//run function once load is complete.
window.onload = function () {
    Fields.init();
}