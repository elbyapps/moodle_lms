// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Hero carousel functionality for theme_elby.
 *
 * @module     theme_elby/herocarousel
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    /**
     * Hero Carousel class.
     */
    class HeroCarousel {
        /**
         * Constructor.
         */
        constructor() {
            this.hero = document.querySelector('.elby-hero');
            if (!this.hero) {
                return;
            }

            this.slides = this.hero.querySelectorAll('.elby-hero-slide');
            this.collages = this.hero.querySelectorAll('.elby-hero-collage');
            this.dots = this.hero.querySelectorAll('.elby-hero-dot');
            this.prevBtn = this.hero.querySelector('.elby-hero-nav-prev');
            this.nextBtn = this.hero.querySelector('.elby-hero-nav-next');

            this.currentSlide = 0;
            this.slideCount = this.slides.length;
            this.autoRotate = this.hero.dataset.autorotate === '1';
            this.interval = parseInt(this.hero.dataset.interval, 10) || 5000;
            this.autoPlayTimer = null;
            this.isPaused = false;

            if (this.slideCount <= 1) {
                return;
            }

            this.bindEvents();
            this.startAutoPlay();
        }

        /**
         * Bind event listeners.
         */
        bindEvents() {
            // Navigation buttons
            if (this.prevBtn) {
                this.prevBtn.addEventListener('click', () => this.prev());
            }
            if (this.nextBtn) {
                this.nextBtn.addEventListener('click', () => this.next());
            }

            // Pagination dots
            this.dots.forEach((dot, index) => {
                dot.addEventListener('click', () => this.goToSlide(index));
            });

            // Pause on hover
            this.hero.addEventListener('mouseenter', () => this.pause());
            this.hero.addEventListener('mouseleave', () => this.resume());

            // Pause on focus (for accessibility)
            this.hero.addEventListener('focusin', () => this.pause());
            this.hero.addEventListener('focusout', () => this.resume());

            // Keyboard navigation
            this.hero.addEventListener('keydown', (e) => this.handleKeydown(e));

            // Touch swipe support
            this.setupTouchEvents();

            // Pause when page is hidden
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.pause();
                } else {
                    this.resume();
                }
            });
        }

        /**
         * Set up touch swipe events.
         */
        setupTouchEvents() {
            let touchStartX = 0;
            let touchEndX = 0;
            const minSwipeDistance = 50;

            this.hero.addEventListener('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            }, {passive: true});

            this.hero.addEventListener('touchend', (e) => {
                touchEndX = e.changedTouches[0].screenX;
                const distance = touchEndX - touchStartX;

                if (Math.abs(distance) >= minSwipeDistance) {
                    if (distance > 0) {
                        this.prev();
                    } else {
                        this.next();
                    }
                }
            }, {passive: true});
        }

        /**
         * Handle keyboard navigation.
         *
         * @param {KeyboardEvent} e The keyboard event.
         */
        handleKeydown(e) {
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                this.prev();
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                this.next();
            }
        }

        /**
         * Go to a specific slide.
         *
         * @param {number} index The slide index.
         */
        goToSlide(index) {
            if (index < 0) {
                index = this.slideCount - 1;
            } else if (index >= this.slideCount) {
                index = 0;
            }

            // Remove active class from current slide
            this.slides[this.currentSlide].classList.remove('active');
            this.slides[this.currentSlide].classList.add('fade-out');
            if (this.collages[this.currentSlide]) {
                this.collages[this.currentSlide].classList.remove('active');
                this.collages[this.currentSlide].classList.add('fade-out');
            }
            if (this.dots[this.currentSlide]) {
                this.dots[this.currentSlide].classList.remove('active');
            }

            // Update current slide
            this.currentSlide = index;

            // Add active class to new slide with animation
            setTimeout(() => {
                this.slides.forEach((slide) => {
                    slide.classList.remove('fade-out');
                });
                this.collages.forEach((collage) => {
                    collage.classList.remove('fade-out');
                });

                this.slides[this.currentSlide].classList.add('active', 'fade-in');
                if (this.collages[this.currentSlide]) {
                    this.collages[this.currentSlide].classList.add('active', 'fade-in');
                }
                if (this.dots[this.currentSlide]) {
                    this.dots[this.currentSlide].classList.add('active');
                }

                // Remove fade-in class after animation
                setTimeout(() => {
                    this.slides[this.currentSlide].classList.remove('fade-in');
                    if (this.collages[this.currentSlide]) {
                        this.collages[this.currentSlide].classList.remove('fade-in');
                    }
                }, 500);
            }, 50);

            // Reset auto-play timer
            this.resetAutoPlay();
        }

        /**
         * Go to previous slide.
         */
        prev() {
            this.goToSlide(this.currentSlide - 1);
        }

        /**
         * Go to next slide.
         */
        next() {
            this.goToSlide(this.currentSlide + 1);
        }

        /**
         * Start auto-play.
         */
        startAutoPlay() {
            if (!this.autoRotate || this.isPaused) {
                return;
            }

            this.autoPlayTimer = setInterval(() => {
                this.next();
            }, this.interval);
        }

        /**
         * Stop auto-play.
         */
        stopAutoPlay() {
            if (this.autoPlayTimer) {
                clearInterval(this.autoPlayTimer);
                this.autoPlayTimer = null;
            }
        }

        /**
         * Reset auto-play timer.
         */
        resetAutoPlay() {
            this.stopAutoPlay();
            this.startAutoPlay();
        }

        /**
         * Pause auto-play.
         */
        pause() {
            this.isPaused = true;
            this.stopAutoPlay();
        }

        /**
         * Resume auto-play.
         */
        resume() {
            this.isPaused = false;
            this.startAutoPlay();
        }
    }

    return {
        /**
         * Initialize the hero carousel.
         *
         * @returns {HeroCarousel} The carousel instance.
         */
        init: function() {
            return new HeroCarousel();
        }
    };
});
