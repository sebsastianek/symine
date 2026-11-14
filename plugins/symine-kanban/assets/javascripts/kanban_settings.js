/**
 * Symine Kanban: the settings page of a board. The form works without this script (two blank rows for new columns);
 * with it, columns are added and reordered freely and the status selects follow the names being typed.
 */
(function ($) {
  'use strict';

  $(function () {
    var rows = $('#kanban-column-rows');
    if (!rows.length) { return; }
    var selects = $('.kanban-status-select');
    var counter = rows.find('.kanban-column-new').length;

    function renumber() {
      rows.find('.kanban-column-row').each(function (index) { $(this).find('.kanban-column-position').val(index + 1); });
    }

    function label(row) {
      var name = $.trim(row.find('.kanban-column-name-input').val());
      return name || row.find('.kanban-column-name-input').attr('placeholder') || row.attr('data-key');
    }

    /** The choices of the status selects follow the columns: their names, their order, the deleted ones */
    function syncOptions() {
      selects.each(function () {
        var select = $(this), value = select.val(), hidden = select.find('option[value="hidden"]');
        select.find('option').filter(function () { return /^[cn]\d+$/.test(this.value); }).remove();
        rows.find('.kanban-column-row').each(function () {
          var row = $(this), key = row.attr('data-key');
          var removed = row.find('input[type="checkbox"]').prop('checked');
          var blank = row.hasClass('kanban-column-new') && !$.trim(row.find('.kanban-column-name-input').val());
          if (removed || (blank && value !== key)) { return; }
          $('<option></option>').val(key).text(label(row)).insertBefore(hidden);
        });
        select.val(select.find('option[value="' + value + '"]').length ? value : '');
        select.closest('tr').toggleClass('kanban-status-unmapped', select.val() === '' && !select.closest('tr').hasClass('kanban-status-unused'));
      });
    }

    $('#kanban-add-column').prop('hidden', false).on('click', function () {
      counter++;
      var source = rows.find('.kanban-column-new').last();
      var key = 'n' + counter;
      var row = source.clone();
      row.attr('data-key', key);
      row.find('input').each(function () { this.name = this.name.replace(/columns\[[^\]]+\]/, 'columns[' + key + ']'); if (this.type !== 'hidden') { this.value = ''; } });
      rows.append(row);
      renumber();
      syncOptions();
      row.find('.kanban-column-name-input').trigger('focus');
    });

    rows.on('input', '.kanban-column-name-input', syncOptions).on('change', 'input[type="checkbox"]', function () {
      $(this).closest('tr').toggleClass('kanban-column-deleted', this.checked);
      syncOptions();
    });
    selects.on('change', syncOptions);

    rows.sortable({handle: '.kanban-sort-handle', axis: 'y', items: '> tr', update: function () { renumber(); syncOptions(); }});
    syncOptions();
  });
})(jQuery);
