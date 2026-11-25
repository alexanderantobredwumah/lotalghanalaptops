/* =====================================================================
   LOTAL GHANA — PHP application behaviour
   Progressive enhancement only: every page works with JavaScript off.
   ===================================================================== */

(function () {
  'use strict';

  var config = window.LOTAL || {};

  /* ---------------- sticky header ---------------- */

  var header = document.getElementById('siteHead');
  function onScroll() {
    if (header) header.classList.toggle('stuck', window.scrollY > 24);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------- mobile menu ---------------- */

  var burger = document.querySelector('[data-burger]');
  var mobileNav = document.querySelector('[data-mobile-nav]');
  if (burger && mobileNav) {
    burger.addEventListener('click', function () {
      var open = mobileNav.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---------------- scroll reveals ---------------- */

  var revealed = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealed.length) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    revealed.forEach(function (node) { observer.observe(node); });
  } else {
    revealed.forEach(function (node) { node.classList.add('is-in'); });
  }

  /* ---------------- hero word carousel ---------------- */

  var rotator = document.querySelector('[data-rotate]');
  if (rotator) {
    var words = rotator.querySelectorAll('.word');
    if (words.length > 1) {
      var wordIndex = 0;
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!reduce) {
        var showWord = function (index) {
          words.forEach(function (word, i) {
            word.style.transition = 'transform .55s cubic-bezier(.2,.7,.2,1), opacity .4s';
            word.style.position = 'absolute';
            word.style.inset = '0';
            word.style.transform = i === index ? 'translateY(0)' : (i < index ? 'translateY(-70%)' : 'translateY(70%)');
            word.style.opacity = i === index ? '1' : '0';
          });
        };
        showWord(0);
        setInterval(function () {
          wordIndex = (wordIndex + 1) % words.length;
          showWord(wordIndex);
        }, 2600);
      } else {
        words.forEach(function (word, i) { if (i > 0) word.style.display = 'none'; });
      }
    }
  }

  /* ---------------- testimonial carousel ---------------- */

  document.querySelectorAll('[data-carousel]').forEach(function (box) {
    var slides = box.querySelectorAll('.quote');
    if (!slides.length) return;
    var index = 0;
    var timer = null;
    var counter = box.querySelector('[data-count]');

    function show(next) {
      index = (next + slides.length) % slides.length;
      slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === index); });
      if (counter) {
        counter.textContent = String(index + 1).padStart(2, '0') + ' / ' + String(slides.length).padStart(2, '0');
      }
    }
    function play() { if (!timer) { timer = setInterval(function () { show(index + 1); }, 6500); } }
    function pause() { clearInterval(timer); timer = null; }

    var prev = box.querySelector('[data-prev]');
    var next = box.querySelector('[data-next]');
    if (prev) prev.addEventListener('click', function () { show(index - 1); });
    if (next) next.addEventListener('click', function () { show(index + 1); });
    box.addEventListener('mouseenter', pause);
    box.addEventListener('mouseleave', play);
    if (slides.length > 1) play();
  });

  /* ---------------- image carousel ---------------- */

  document.querySelectorAll('[data-image-carousel]').forEach(function (box) {
    var slides = box.querySelectorAll('.slide');
    var dots = box.querySelectorAll('[data-dot]');
    if (slides.length < 2) return;
    var current = 0;
    var timer = setInterval(advance, 7000);

    function advance(step) {
      current = (current + (typeof step === 'number' ? step : 1)) % slides.length;
      slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === current); });
      dots.forEach(function (dot, i) { dot.classList.toggle('on', i === current); });
    }
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        clearInterval(timer);
        advance(parseInt(dot.getAttribute('data-dot'), 10) - current);
        timer = setInterval(function () { advance(1); }, 7000);
      });
    });
    box.addEventListener('mouseenter', function () { clearInterval(timer); });
    box.addEventListener('mouseleave', function () { timer = setInterval(function () { advance(1); }, 7000); });
  });

  /* ---------------- tracking code input ---------------- */

  var codeInput = document.querySelector('[data-code-input]');
  if (codeInput) {
    codeInput.addEventListener('input', function () {
      var raw = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12);
      if (raw.indexOf('LT') !== 0) raw = 'LT' + raw;
      var body = raw.replace(/^LT/, '').slice(0, 9);
      var parts = ['LT'];
      if (body.length) parts.push(body.slice(0, 4));
      if (body.length > 4) parts.push(body.slice(4, 8));
      if (body.length > 8) parts.push(body.slice(8, 9));
      codeInput.value = parts.join('-');
    });
  }

  /* ---------------- checkout: live delivery fee ---------------- */

  var citySelect = document.querySelector('[data-city]');
  if (citySelect && config.deliveryFees) {
    var money = function (pesewas) {
      var cedis = pesewas / 100;
      return 'GH₵' + cedis.toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var short = function (pesewas) {
      var cedis = pesewas / 100;
      return Number.isInteger(cedis) ? 'GH₵' + cedis.toLocaleString('en-GH') : money(pesewas);
    };
    var subtotalNode = document.querySelector('[data-subtotal]');
    var deliveryNode = document.querySelector('[data-delivery]');
    var totalNode = document.querySelector('[data-total]');
    var labelNode = document.querySelector('[data-city-label]');

    var recalc = function () {
      var city = citySelect.value;
      var fees = config.deliveryFees || {};
      var subtotalText = (subtotalNode ? subtotalNode.textContent : '').replace(/[^0-9.]/g, '');
      var subtotal = Math.round(parseFloat(subtotalText || '0') * 100);
      var fee = fees[city] === undefined ? fees.Other : fees[city];
      if (subtotal >= (config.freeDeliveryOver || 0) && (city === 'Accra' || city === 'Tema')) fee = 0;
      if (labelNode) labelNode.textContent = city;
      if (deliveryNode) deliveryNode.textContent = money(fee);
      if (totalNode) totalNode.textContent = short(subtotal + fee);
    };
    citySelect.addEventListener('change', recalc);
    recalc();
  }

  /* ---------------- Paystack inline checkout ---------------- */

  function loadPaystack() {
    return new Promise(function (resolve, reject) {
      if (window.PaystackPop) return resolve(window.PaystackPop);
      var script = document.createElement('script');
      script.src = 'https://js.paystack.co/v1/inline.js';
      script.async = true;
      script.onload = function () { resolve(window.PaystackPop); };
      script.onerror = function () { reject(new Error('Could not reach Paystack. Check your connection.')); };
      document.body.appendChild(script);
    });
  }

  function post(url, payload, csrfToken) {
    var headers = {
      'Content-Type': 'application/json',
      'X-Requested-With': 'fetch',
      'Accept': 'application/json',
    };
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    }).then(function (response) {
      return response.json().then(function (data) { return { ok: response.ok, data: data }; });
    });
  }

  document.querySelectorAll('[data-paystack]').forEach(function (box) {
    var button = box.querySelector('[data-pay-now]');
    var error = box.querySelector('[data-pay-error]');
    if (!button) return;

    function fail(message) {
      if (!error) return;
      error.textContent = message;
      error.hidden = false;
    }

    button.addEventListener('click', function () {
      if (error) error.hidden = true;
      button.disabled = true;
      var label = button.textContent;
      button.textContent = 'Opening secure checkout…';

      var csrfToken = box.getAttribute('data-csrf');
      post(config.payUrl, { code: box.getAttribute('data-code') }, csrfToken)
        .then(function (result) {
          if (!result.ok || !result.data.ok) throw new Error(result.data.error || 'Paystack could not start this payment.');
          return loadPaystack().then(function (PaystackPop) {
            PaystackPop.setup({
              key: config.paystackPublicKey,
              email: box.getAttribute('data-email'),
              amount: box.getAttribute('data-amount'),
              currency: box.getAttribute('data-currency') || 'GHS',
              ref: result.data.reference,
              metadata: { custom_fields: [{ display_name: 'Order', variable_name: 'order_code', value: box.getAttribute('data-code') }] },
              first_name: (box.getAttribute('data-name') || '').split(' ')[0],
              callback: function (response) {
                post(config.confirmUrl, { reference: response.reference }, csrfToken)
                  .then(function (verified) {
                    if (!verified.ok || !verified.data.ok) throw new Error(verified.data.error || 'Paystack has not confirmed this payment yet.');
                    window.location.href = window.location.pathname.replace('/pay/', '/order/') + '?paid=1';
                  })
                  .catch(function (error2) { fail(error2.message); button.disabled = false; button.textContent = label; });
              },
              onClose: function () {
                fail('Payment window closed. Your order is still saved — you can pay any time.');
                button.disabled = false;
                button.textContent = label;
              },
            }).openIframe();
          });
        })
        .catch(function (error3) {
          fail(error3.message);
          button.disabled = false;
          button.textContent = label;
        });
    });
  });

  /* ---------------- auto-dismiss flash banners ---------------- */

  document.querySelectorAll('.banner-ok, .banner-warn').forEach(function (banner) {
    setTimeout(function () {
      banner.style.transition = 'opacity .4s, transform .4s';
      banner.style.opacity = '0';
      banner.style.transform = 'translateY(-6px)';
    }, 9000);
  });
})();
