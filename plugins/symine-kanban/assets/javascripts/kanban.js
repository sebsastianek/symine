/**
 * Symine Kanban: the board page.
 *
 * Cards are dragged with jQuery UI sortable (bundled with Redmine). A card knows where it may go (data-moves:
 * column id => statuses it can take there, worked out by the server from the workflow), so the columns that
 * would refuse it are dimmed while it is dragged. A drop is sent to the server; when it is refused the card
 * goes back and the reason is shown.
 *
 * A click on a card opens its dialog in place (a <dialog>, "#card-<id>" in the address so it can be linked to):
 * the issue with its description, files, subtasks and activity; fields save as they change, comments are added
 * without leaving the board. The server answers every change with the card and the dialog as they are now.
 */
(function ($) {
  'use strict';

  var holder, root, dragging = false, busy = 0, timer = null;

  /** The board elements; they are looked up when needed because the board is redrawn (and replaced) at times */
  function ensure() {
    holder = holder || document.getElementById('kanban-holder');
    root = document.getElementById('kanban');
    return !!(holder && root);
  }

  /**
   * Handlers are delegated from the document and registered as soon as this script is read, not at DOM-ready:
   * the board answers a click from the moment it is on screen.
   */
  var board = {
    on: function (type, selector, handler) {
      $(document).on(type, '#kanban-holder ' + selector, function (event) {
        if (ensure()) { return handler.call(this, event); }
      });
      return this;
    }
  };

  function csrf() { return $('meta[name="csrf-token"]').attr('content') || ''; }
  function text(name) { return root.getAttribute('data-text-' + name) || ''; }
  function statuses() { try { return JSON.parse(holder.getAttribute('data-statuses') || '{}'); } catch (e) { return {}; } }
  function query() { return window.location.search || ''; }

  function message(lines, kind) {
    var box = $('<div class="flash ' + (kind || 'error') + ' kanban-message" role="alert"></div>');
    $.each([].concat(lines), function (i, line) { box.append($('<div></div>').text(line)); });
    $('#kanban-messages').empty().append(box);
    window.clearTimeout(message.timer);
    message.timer = window.setTimeout(function () { box.fadeOut(300, function () { box.remove(); }); }, 8000);
  }

  function request(url, data) {
    busy++;
    return $.ajax({url: url + query(), method: 'POST', data: data, dataType: 'json', headers: {'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest'}})
      .always(function () { busy--; });
  }

  function errorsOf(xhr) {
    var json = xhr && xhr.responseJSON;
    return json && json.errors && json.errors.length ? json.errors : [text('failed')];
  }

  /* ----- numbers of the column headers ----- */
  function applySummary(summary) {
    if (!summary) { return; }
    if (summary.version) { root.setAttribute('data-version', summary.version); }
    $.each(summary.columns || {}, function (id, column) {
      var head = $(root).find('.kanban-column-head[data-column="' + id + '"]');
      head.find('.kanban-count-value').text(column.count);
      head.toggleClass('kanban-over-limit', !!column.over_limit);
    });
    $(root).find('.kanban-lane').each(function () {
      $(this).find('.kanban-lane-head .kanban-count').text($(this).find('.kanban-card').length);
    });
  }

  /* ----- moving cards ----- */
  function movesOf(card) { try { return JSON.parse(card.getAttribute('data-moves') || '{}'); } catch (e) { return {}; } }

  function markTargets(card) {
    var moves = movesOf(card), lane = card.getAttribute('data-lane'), laneMovable = root.getAttribute('data-lane-movable') === '1';
    $(root).find('.kanban-cell').each(function () {
      var ok = Object.prototype.hasOwnProperty.call(moves, this.getAttribute('data-column'))
        && (laneMovable || this.getAttribute('data-lane') === lane);
      $(this).toggleClass('kanban-drop-ok', ok).toggleClass('kanban-drop-no', !ok);
    });
  }

  function clearTargets() { $(root).find('.kanban-cell').removeClass('kanban-drop-ok kanban-drop-no'); }

  /** Asks which status the card takes when the column offers several; resolves with the status id, rejects on cancel */
  function chooseStatus(card, ids) {
    var done = $.Deferred(), names = statuses();
    if (ids.length < 2) { return done.resolve(ids[0] || '').promise(); }
    var menu = $('<div class="kanban-chooser" role="dialog"></div>').attr('aria-label', text('choose-status'));
    menu.append($('<p class="kanban-chooser-title"></p>').text(text('choose-status')));
    $.each(ids, function (i, id) {
      menu.append($('<button type="button" class="kanban-chooser-option"></button>').text(names[id] || ('#' + id)).on('click', function () { menu.remove(); done.resolve(id); }));
    });
    menu.append($('<button type="button" class="kanban-chooser-cancel"></button>').text(text('cancel')).on('click', function () { menu.remove(); done.reject(); }));
    $(card).append(menu);
    menu.find('button').first().trigger('focus');
    menu.on('keydown', function (event) { if (event.key === 'Escape') { menu.remove(); done.reject(); } });
    return done.promise();
  }

  function dropped(card, origin) {
    var cell = $(card).closest('.kanban-cards');
    var column = cell.attr('data-column'), lane = cell.attr('data-lane');
    var moves = movesOf(card);
    var sameColumn = column === origin.column, sameLane = lane === origin.lane;
    var manual = root.getAttribute('data-manual-order') === '1';
    var laneMovable = root.getAttribute('data-lane-movable') === '1';

    function revert() {
      if (origin.next && origin.next.parentNode === origin.parent) { origin.parent.insertBefore(card, origin.next); } else { origin.parent.appendChild(card); }
    }

    if (!Object.prototype.hasOwnProperty.call(moves, column) || (!sameLane && !laneMovable)) {
      revert();
      message(text('not-allowed'));
      return;
    }
    if (sameColumn && sameLane && !manual) { revert(); return; }

    chooseStatus(card, moves[column] || []).done(function (statusId) {
      var next = $(card).nextAll('.kanban-card').first();
      $(card).addClass('kanban-card-saving');
      request(root.getAttribute('data-move-url').replace(/\/0\/move$/, '/' + card.getAttribute('data-issue-id') + '/move'), {
        column_id: column,
        status_id: statusId || '',
        before_id: next.length ? next.attr('data-issue-id') : '',
        lane: sameLane ? '' : lane
      }).done(function (json) {
        var fresh = $(json.card);
        fresh.attr('data-lane', lane);
        $(card).replaceWith(fresh);
        applySummary(json);
      }).fail(function (xhr) {
        $(card).removeClass('kanban-card-saving');
        revert();
        message(errorsOf(xhr));
      });
    }).fail(revert);
  }

  function initDrag() {
    var origin = null;
    $(root).find('.kanban-cards').sortable({
      connectWith: '#kanban .kanban-cell:not(.kanban-cell-unmapped) .kanban-cards',
      items: '> .kanban-card[data-movable]',
      placeholder: 'kanban-placeholder',
      forcePlaceholderSize: true,
      tolerance: 'pointer',
      distance: 5,
      scroll: true,
      cancel: 'a, button, input, select, .kanban-chooser',
      start: function (event, ui) {
        dragging = true;
        var card = ui.item[0];
        origin = {parent: card.parentNode, next: null, column: card.getAttribute('data-column'), lane: card.getAttribute('data-lane')};
        // the element following the card before it was picked up (the placeholder took its place)
        var following = $(ui.placeholder).nextAll('.kanban-card').not(card).first();
        origin.next = following.length ? following[0] : null;
        ui.item.addClass('kanban-card-dragging');
        markTargets(card);
      },
      stop: function (event, ui) {
        dragging = false;
        justDragged = true;
        window.setTimeout(function () { justDragged = false; }, 50);
        ui.item.removeClass('kanban-card-dragging');
        clearTargets();
        if (origin) { dropped(ui.item[0], origin); }
        origin = null;
      }
    });
  }

  /* ----- adding a card ----- */
  /** The tracker select only holds the trackers of the chosen project (the template lists them for every project) */
  function syncTrackers(form) {
    var project = String(form.find('select[name="project_id"]').val()), select = form.find('select[name="tracker_id"]');
    var all = form.data('trackers');
    if (!all) { all = select.find('option').clone(); form.data('trackers', all); }
    var chosen = select.val();
    select.empty().append(all.filter(function () { return this.getAttribute('data-project') === project; }).clone());
    if (select.find('option[value="' + chosen + '"]').length) { select.val(chosen); }
  }

  function openAdd(columnId, atBottom) {
    var template = document.getElementById('kanban-add-template');
    if (!template) { return; }
    $(root).find('.kanban-add').remove();
    var form = $(template.content.firstElementChild.cloneNode(true));
    form.attr('data-column', columnId);
    var cell = $(root).find('.kanban-cell[data-column="' + columnId + '"]').first();
    if (atBottom) { form.insertAfter(cell.find('.kanban-cards').first()); } else { cell.prepend(form); }
    syncTrackers(form);
    form.find('input[name="subject"]').trigger('focus');
  }

  function submitAdd(form) {
    var errors = form.find('.kanban-add-errors').prop('hidden', true).empty();
    form.find('input[type="submit"]').prop('disabled', true);
    request(root.getAttribute('data-create-url'), {
      subject: form.find('input[name="subject"]').val(),
      project_id: form.find('select[name="project_id"]').val(),
      tracker_id: form.find('select[name="tracker_id"]').val(),
      column_id: form.attr('data-column')
    }).done(function (json) {
      var card = $(json.card);
      // the new issue may start in another column than the one it was added from
      var target = $(root).find('.kanban-cards[data-column="' + card.attr('data-column') + '"]').first();
      if (!target.length) { target = form.closest('.kanban-cell').find('.kanban-cards'); }
      target.append(card);
      applySummary(json);
      form.find('input[name="subject"]').val('').trigger('focus');
    }).fail(function (xhr) {
      $.each(errorsOf(xhr), function (i, line) { errors.append($('<span></span>').text(line)).append('<br>'); });
      errors.prop('hidden', false);
    }).always(function () { form.find('input[type="submit"]').prop('disabled', false); });
  }

  /* ----- the dialog of a card ----- */
  var redrawOnClose = false, justDragged = false;

  function dialogEl() { return document.getElementById('kanban-dialog'); }
  function dialogOpen() { var d = dialogEl(); return !!(d && d.open); }
  function dialogBody() { return $(dialogEl()).find('.kanban-dialog-body'); }

  function dialogErrors(lines) {
    var box = dialogBody().find('.kanban-dialog-errors').empty();
    $.each([].concat(lines), function (i, line) { box.append($('<div></div>').text(line)); });
    box.prop('hidden', !lines.length);
    if (lines.length && box[0]) { box[0].scrollIntoView({block: 'nearest'}); }
  }

  function cardUrl(id) {
    var card = document.getElementById('kanban-card-' + id);
    return card ? card.getAttribute('data-card-url') : root.getAttribute('data-move-url').replace(/\/0\/move$/, '/' + id);
  }

  function loadDialog(id, errors) {
    return $.get(cardUrl(id) + query()).done(function (html) {
      dialogEl().innerHTML = html;
      if (errors && errors.length) { dialogErrors(errors); }
    });
  }

  function openCard(id, fallbackHref) {
    var d = dialogEl();
    if (!d || typeof d.showModal !== 'function') { if (fallbackHref) { window.location.href = fallbackHref; } return; }
    d.innerHTML = '<p class="kanban-dialog-loading"></p>';
    $(d).find('.kanban-dialog-loading').text(d.getAttribute('data-text-loading') || '');
    if (!d.open) { d.showModal(); }
    loadDialog(id).done(function () {
      window.history.replaceState(null, '', window.location.pathname + window.location.search + '#card-' + id);
      $(d).find('.kanban-dialog-close').trigger('focus');
    }).fail(function () {
      // not a card of this board (a subtask in another project, a filtered-out issue): its own page
      d.close();
      if (fallbackHref) { window.location.href = fallbackHref; } else { message(text('failed')); }
    });
  }

  function closeDialog() {
    var d = dialogEl();
    if (d && d.open) { d.close(); }
  }

  function dialogClosed() {
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
    if (redrawOnClose) { redrawOnClose = false; redraw(); }
  }

  /** Puts a card (as answered by the server) where it belongs on the board now */
  function placeCard(html) {
    var fresh = $(html), id = fresh.attr('id'), old = $('#' + id);
    var sameColumn = old.length && old.attr('data-column') === fresh.attr('data-column');
    var laned = $(root).hasClass('kanban-with-lanes');
    if (sameColumn && (!laned || old.attr('data-lane') === fresh.attr('data-lane'))) { old.replaceWith(fresh); return; }
    var target = $(root).find('.kanban-cards[data-column="' + fresh.attr('data-column') + '"]' + (laned ? '[data-lane="' + fresh.attr('data-lane') + '"]' : '')).first();
    old.remove();
    // a column or lane that is not drawn yet (hidden status, new lane): the board is redrawn once the dialog closes
    if (target.length) { target.append(fresh); } else { redrawOnClose = true; }
  }

  function sendFromDialog(url, data, options) {
    var body = dialogBody(), id = body.attr('data-issue-id');
    var settings = {url: url + query(), method: 'POST', data: data, dataType: 'json', headers: {'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest'}};
    if (window.FormData && data instanceof FormData) { settings.processData = false; settings.contentType = false; }
    body.addClass('kanban-dialog-saving');
    busy++;
    return $.ajax(settings).done(function (json) {
      if (json.card) { placeCard(json.card); }
      applySummary(json);
      if (json.dialog && dialogOpen()) { dialogEl().innerHTML = json.dialog; }
      if (options && options.redraw) { redrawOnClose = true; }
      if (options && options.focus) { dialogBody().find(options.focus).trigger('focus'); }
    }).fail(function (xhr) {
      body.removeClass('kanban-dialog-saving');
      // show the issue as it is, with what was refused
      var errors = errorsOf(xhr);
      if (options && options.keep) { dialogErrors(errors); } else { loadDialog(id, errors); }
    }).always(function () { busy--; });
  }

  function bindDialog() {
    board
      // opening: a click on a card or on its links (modifier keys and the middle button keep their usual meaning)
      .on('click', '.kanban-card', function (event) {
        if (justDragged || event.metaKey || event.ctrlKey || event.shiftKey || event.button) { return; }
        var link = $(event.target).closest('a');
        if ($(event.target).closest('button, input, select, textarea, .kanban-chooser').length) { return; }
        if (link.length && !link.is('.kanban-card-subject, .kanban-card-id')) { return; }
        event.preventDefault();
        openCard(this.getAttribute('data-issue-id'), link.attr('href') || $(this).find('.kanban-card-subject').attr('href'));
      })
      .on('click', '.kanban-dialog [data-open-card]', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button) { return; }
        event.preventDefault();
        openCard(this.getAttribute('data-open-card'), this.getAttribute('href'));
      })
      .on('click', '.kanban-dialog-close', closeDialog)
      .on('click', '.kanban-dialog', function (event) { if (event.target === this) { closeDialog(); } })
      // editing the title and the description in place
      .on('click', '.kanban-dialog [data-edit]', function () {
        var form = dialogBody().find('.kanban-dialog-inline[data-field="' + this.getAttribute('data-edit') + '"]');
        form.prop('hidden', false).find('input[type="text"], textarea').first().trigger('focus');
        dialogBody().find('[data-view="' + this.getAttribute('data-edit') + '"]').prop('hidden', true);
      })
      .on('click', '.kanban-dialog [data-cancel]', function () {
        var form = $(this).closest('.kanban-dialog-inline');
        form.prop('hidden', true)[0].reset();
        dialogBody().find('[data-view="' + form.attr('data-field') + '"]').prop('hidden', false);
      })
      .on('submit', '.kanban-dialog-inline', function (event) {
        event.preventDefault();
        sendFromDialog(dialogBody().attr('data-update-url'), $(this).serialize(), {keep: true});
      })
      // the fields of the side panel save as soon as they change
      .on('change', '.kanban-dialog [data-auto]', function () {
        var data = {};
        data[this.name] = this.value;
        sendFromDialog(dialogBody().attr('data-update-url'), data, {focus: '#' + this.id});
      })
      .on('click', '.kanban-dialog [data-set]', function () {
        var data = {};
        data[this.getAttribute('data-set')] = this.getAttribute('data-value');
        sendFromDialog(dialogBody().attr('data-update-url'), data);
      })
      .on('submit', '.kanban-dialog-comment', function (event) {
        event.preventDefault();
        sendFromDialog(dialogBody().attr('data-update-url'), $(this).serialize(), {keep: true, focus: '.kanban-dialog-comment textarea'});
      })
      .on('keydown', '.kanban-dialog-comment textarea', function (event) {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) { $(this.form).trigger('submit'); }
      })
      .on('submit', '.kanban-dialog-subtask', function (event) {
        event.preventDefault();
        sendFromDialog(dialogBody().attr('data-subtask-url'), $(this).serialize(), {keep: true, redraw: true, focus: '.kanban-dialog-subtask input[type="text"]'});
      })
      .on('change', '.kanban-dialog-attach input[type="file"]', function () {
        if (this.files && this.files.length) { sendFromDialog(dialogBody().attr('data-attach-url'), new FormData(this.form), {keep: true}); }
      })
      .on('click', '.kanban-dialog [data-watch]', function () { sendFromDialog(dialogBody().attr('data-watch-url'), {}); });

    // "close" also fires for the Escape key; the dialog element is replaced when the board is redrawn
    document.addEventListener('close', function (event) { if (event.target && event.target.id === 'kanban-dialog') { dialogClosed(); } }, true);

  }

  /* ----- swimlanes ----- */
  function laneKey() { return 'kanban.' + root.getAttribute('data-board-id') + '.collapsed'; }
  function collapsedLanes() { try { return JSON.parse(window.localStorage.getItem(laneKey()) || '[]'); } catch (e) { return []; } }
  function restoreLanes() {
    var collapsed = collapsedLanes();
    $(root).find('.kanban-lane').each(function () {
      var closed = collapsed.indexOf(this.getAttribute('data-lane')) !== -1;
      $(this).toggleClass('kanban-lane-collapsed', closed).find('.kanban-lane-toggle').attr('aria-expanded', closed ? 'false' : 'true');
    });
  }
  function toggleLane(lane) {
    var key = lane.attr('data-lane'), collapsed = collapsedLanes(), at = collapsed.indexOf(key);
    if (at === -1) { collapsed.push(key); } else { collapsed.splice(at, 1); }
    try { window.localStorage.setItem(laneKey(), JSON.stringify(collapsed)); } catch (e) { /* private browsing */ }
    restoreLanes();
  }

  /* ----- keeping up with what others change ----- */
  function redraw() {
    return $.get(root.getAttribute('data-cards-url') + query()).done(function (html) {
      if (dragging || busy || dialogOpen() || $(root).find('.kanban-add, .kanban-chooser').length) { return; }
      var scroll = $(root).find('.kanban-scroll').scrollLeft();
      holder.innerHTML = html;
      setup();
      $(root).find('.kanban-scroll').scrollLeft(scroll);
    });
  }

  function watch() {
    window.clearInterval(timer);
    var seconds = parseInt(root.getAttribute('data-refresh'), 10) || 0;
    if (seconds < 5) { return; }
    timer = window.setInterval(function () {
      if (document.hidden || dragging || busy || dialogOpen() || $(root).find('.kanban-add, .kanban-chooser').length) { return; }
      $.getJSON(root.getAttribute('data-version-url') + query()).done(function (json) {
        if (json.version && json.version !== root.getAttribute('data-version')) { redraw(); }
      });
    }, seconds * 1000);
  }

  function setup() {
    root = document.getElementById('kanban');
    if (!root) { return; }
    initDrag();
    restoreLanes();
    watch();
  }

  function bindBoard() {
    board
      .on('click', '.kanban-add-button', function () { openAdd(this.getAttribute('data-column')); })
      .on('click', '.kanban-add-link', function () { openAdd(this.getAttribute('data-column'), true); })
      .on('click', '.kanban-add-cancel', function () { $(this).closest('.kanban-add').remove(); })
      .on('change', '.kanban-add select[name="project_id"]', function () { syncTrackers($(this).closest('.kanban-add')); })
      .on('submit', '.kanban-add', function (event) { event.preventDefault(); submitAdd($(this)); })
      .on('keydown', '.kanban-add', function (event) { if (event.key === 'Escape') { $(this).remove(); } })
      .on('click', '.kanban-lane-toggle', function () { toggleLane($(this).closest('.kanban-lane')); })
      .on('click', '.kanban-more', function () {
        var button = $(this), column = button.attr('data-column'), offset = parseInt(button.attr('data-offset'), 10) || 0;
        button.prop('disabled', true);
        $.get(root.getAttribute('data-cards-url') + (query() ? query() + '&' : '?') + 'column=' + encodeURIComponent(column) + '&offset=' + offset).done(function (html) {
          var cards = $($.parseHTML(html)).filter('.kanban-card');
          button.closest('.kanban-cell').find('.kanban-cards').append(cards);
          var shown = offset + cards.length, total = parseInt(button.attr('data-total'), 10) || 0;
          if (!cards.length || shown >= total) { button.remove(); } else { button.attr('data-offset', shown).prop('disabled', false).text(button.text().replace(/\d+/, total - shown)); }
        }).fail(function () { button.prop('disabled', false); });
      });
  }

  bindBoard();
  bindDialog();

  $(function () {
    if (!ensure()) { return; }
    setup();

    // filters apply as soon as a choice is made; the board switcher goes to the chosen board
    $('#kanban-filters').on('change', 'select', function () { this.form.submit(); });
    $('#kanban-filters .kanban-filters-apply').addClass('kanban-js-hidden');
    $('#kanban-switch').on('change', function () { window.location.href = this.value; });

    // a link to a card opens it
    var linked = /^#card-(\d+)$/.exec(window.location.hash);
    if (linked) { openCard(linked[1]); }
  });

  /* ----- touch screens: jQuery UI sortable only listens to the mouse ----- */
  (function () {
    if (!('ontouchstart' in window)) { return; }
    var moved = false, active = null;
    function relay(type, touch) {
      var event = new MouseEvent(type, {bubbles: true, cancelable: true, view: window, screenX: touch.screenX, screenY: touch.screenY, clientX: touch.clientX, clientY: touch.clientY, button: 0});
      (type === 'mousedown' ? touch.target : document).dispatchEvent(event);
    }
    document.addEventListener('touchstart', function (event) {
      var card = event.target.closest && event.target.closest('#kanban .kanban-card[data-movable]');
      if (!card || event.touches.length > 1 || event.target.closest('a, button, input, select')) { return; }
      active = card; moved = false;
      relay('mousedown', event.changedTouches[0]);
    }, {passive: true});
    document.addEventListener('touchmove', function (event) {
      if (!active) { return; }
      moved = true;
      if (dragging) { event.preventDefault(); }
      relay('mousemove', event.changedTouches[0]);
    }, {passive: false});
    document.addEventListener('touchend', function (event) {
      if (!active) { return; }
      relay('mouseup', event.changedTouches[0]);
      active = null;
    });
  })();
})(jQuery);
