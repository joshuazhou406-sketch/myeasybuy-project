(function () {
	'use strict';

	function initHero() {
		var slides = document.querySelectorAll('.meb-hero__slide');
		var dots = document.querySelectorAll('.meb-hero__dot');
		if (slides.length < 2) {
			return;
		}

		var current = 0;
		var timer = null;

		function show(index) {
			current = (index + slides.length) % slides.length;
			slides.forEach(function (slide, i) {
				slide.classList.toggle('is-active', i === current);
				slide.setAttribute('aria-hidden', i === current ? 'false' : 'true');
			});
			dots.forEach(function (dot, i) {
				dot.classList.toggle('is-active', i === current);
			});
		}

		function start() {
			stop();
			timer = window.setInterval(function () {
				show(current + 1);
			}, 5000);
		}

		function stop() {
			if (timer) {
				window.clearInterval(timer);
			}
		}

		dots.forEach(function (dot) {
			dot.addEventListener('click', function () {
				show(parseInt(dot.getAttribute('data-meb-slide'), 10) || 0);
				start();
			});
		});

		var hero = document.querySelector('.meb-hero');
		hero.addEventListener('mouseenter', stop);
		hero.addEventListener('mouseleave', start);
		start();
	}

	document.addEventListener('DOMContentLoaded', initHero);
})();
