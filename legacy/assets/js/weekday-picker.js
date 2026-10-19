// weekday-picker.js
// کنترل مستقل انتخاب روزهای هفته

(function () {
    /**
     * نگاشت عدد روز به نام فارسی
     * 1 = شنبه ... 7 = جمعه
     */
    const WEEKDAYS = [
        { value: 1, label: 'شنبه' },
        { value: 2, label: 'یکشنبه' },
        { value: 3, label: 'دوشنبه' },
        { value: 4, label: 'سه‌شنبه' },
        { value: 5, label: 'چهارشنبه' },
        { value: 6, label: 'پنج‌شنبه' },
        { value: 7, label: 'جمعه' }
    ];

    /**
     * کلاس اصلی انتخاب‌گر روز
     */
    class WeekdayPicker {
        /**
         * @param {HTMLElement} rootElement - عنصر ریشه‌ای که کنترل داخل آن رندر می‌شود
         * @param {Object} options - تنظیمات اولیه
         *   options.name: نام فیلد (برای استفاده در فرم)
         *   options.initialValues: آرایه‌ای از شماره روزها [1..7] که در ابتدا انتخاب باشند
         */
        constructor(rootElement, options = {}) {
            if (!rootElement) {
                throw new Error('WeekdayPicker: rootElement is required');
            }

            this.root = rootElement;
            this.name = options.name || this.root.dataset.name || 'weekdays';
            this.selectedValues = new Set(
                Array.isArray(options.initialValues)
                    ? options.initialValues.map(Number)
                    : []
            );

            this._render();
            this._bindEvents();
        }

        /**
         * رندر دکمه‌های روزهای هفته و یک input مخفی برای ارسال در فرم
         */
        _render() {
            this.root.classList.add('weekday-picker');

            // پاک کردن قبلی‌ها (اگر دوباره initialize شد)
            this.root.innerHTML = '';

            // ایجاد pills روزها
            WEEKDAYS.forEach(day => {
                const pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'weekday-pill';
                pill.textContent = day.label;
                pill.dataset.value = String(day.value);

                if (this.selectedValues.has(day.value)) {
                    pill.classList.add('selected');
                }

                this.root.appendChild(pill);
            });

            // input مخفی برای استفاده در submit فرم
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = this.name;
            hidden.value = this.getValueAsString();

            this.hiddenInput = hidden;
            this.root.appendChild(hidden);
        }

        /**
         * بایند کردن رویدادها
         */
        _bindEvents() {
            this.root.addEventListener('click', (e) => {
                const pill = e.target.closest('.weekday-pill');
                if (!pill || pill.classList.contains('disabled')) return;

                const value = Number(pill.dataset.value);
                if (this.selectedValues.has(value)) {
                    this.selectedValues.delete(value);
                    pill.classList.remove('selected');
                } else {
                    this.selectedValues.add(value);
                    pill.classList.add('selected');
                }

                this._syncHiddenInput();
            });
        }

        /**
         * به‌روزرسانی مقدار input مخفی بر اساس انتخاب فعلی
         */
        _syncHiddenInput() {
            if (this.hiddenInput) {
                this.hiddenInput.value = this.getValueAsString();
            }
        }

        /**
         * برگرداندن مقادیر انتخاب‌شده به صورت آرایه‌ای از اعداد
         * مثال: [1, 3, 6]
         */
        getSelectedDays() {
            return Array.from(this.selectedValues).sort((a, b) => a - b);
        }

        /**
         * برگرداندن مقادیر انتخاب‌شده به صورت رشته‌ای برای ذخیره‌سازی
         * مثال: "1,3,6"
         */
        getValueAsString() {
            return this.getSelectedDays().join(',');
        }

        /**
         * ست کردن برنامه‌ریزی شده روزهای انتخابی
         * @param {number[]} days
         */
        setSelectedDays(days) {
            this.selectedValues = new Set(
                Array.isArray(days) ? days.map(Number) : []
            );
            // دوباره رندر می‌کنیم تا UI به‌روزرسانی شود
            this._render();
            this._bindEvents();
        }

        /**
         * غیرفعال / فعال کردن کنترل
         * @param {boolean} disabled
         */
        setDisabled(disabled) {
            const pills = this.root.querySelectorAll('.weekday-pill');
            pills.forEach(pill => {
                if (disabled) {
                    pill.classList.add('disabled');
                } else {
                    pill.classList.remove('disabled');
                }
            });
        }
    }

    // ذخیره در window تا در فایل‌های دیگر هم قابل دسترس باشد
    window.WeekdayPicker = WeekdayPicker;

    /**
     * Auto-init:
     * اگر در صفحه عناصری با کلاس .weekday-picker-auto وجود داشته باشد،
     * به‌صورت خودکار تبدیل به picker می‌شوند.
     *
     * مثال HTML:
     * <div class="weekday-picker-auto" data-name="schedule-days"></div>
     */
    document.addEventListener('DOMContentLoaded', () => {
        const autoPickers = document.querySelectorAll('.weekday-picker-auto');
        autoPickers.forEach(el => {
            new WeekdayPicker(el, {
                name: el.dataset.name
            });
        });
    });

})();
