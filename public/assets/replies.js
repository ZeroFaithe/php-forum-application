// Optional challenge: collapse / expand reply branches.
//
// Progressive enhancement: the server renders every reply expanded and no
// toggle buttons. This script adds a real <button type="button"> only to
// comments that have a child-reply list, so with JavaScript disabled all
// comments stay visible and no dead controls appear.
// No comment text is read or written here; labels are fixed strings.
(function () {
  'use strict';

  function setState(button, list, expanded) {
    list.hidden = !expanded;
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    var count = Number(list.getAttribute('data-reply-count')) || 0;
    var noun = count === 1 ? 'reply' : 'replies';
    button.textContent = (expanded ? 'Hide ' : 'Show ') + noun + ' (' + count + ')';
  }

  function init() {
    var lists = document.querySelectorAll('ul.replies[id]');
    Array.prototype.forEach.call(lists, function (list) {
      var item = list.parentElement; // the <li class="comment">
      var actions = item && item.querySelector(':scope > article .comment-actions');
      if (!actions) return;

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'toggle-replies';
      button.setAttribute('aria-controls', list.id);
      setState(button, list, true);

      button.addEventListener('click', function () {
        setState(button, list, button.getAttribute('aria-expanded') !== 'true');
      });

      actions.insertBefore(button, actions.firstChild);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
