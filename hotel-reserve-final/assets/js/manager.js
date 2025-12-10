/**
 * Hotel Reserve Manager - Admin JavaScript
 * نسخه debug شده و تصحیح شده
 */

(function($) {
    'use strict';
    
    const Manager = {
        currentHotel: null,
        currentRoom: null,
        currentMonth: null,
        currentYear: null,
        priceChanges: {},
        
        init: function() {
            console.log('✓ Manager.init شروع شد');
            this.setupFilters();
            this.loadCategories();
            this.loadHotels();
            this.setupModal();
            this.setupCalendar();
            this.initializeDate();
            this.setupRoomEvents();
            console.log('✓ Manager.init تمام شد');
        },
        
        initializeDate: function() {
            const now = new Date();
            const jalali = this.gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
            this.currentYear = jalali[0];
            this.currentMonth = jalali[1];
        },
        
        setupFilters: function() {
            $('#apply-filters').on('click', () => this.loadHotels());
            $('#reset-filters').on('click', () => {
                $('#hotel-search').val('');
                $('#filter-rating').val('');
                $('#filter-category').val('');
                this.loadHotels();
            });
            
            $('#hotel-search').on('keypress', (e) => {
                if (e.which === 13) this.loadHotels();
            });
        },
        
        setupRoomEvents: function() {
            console.log('✓ تنظیم رویدادهای اتاق با event delegation');
            
            // حذف رویدادهای قبلی
            $(document).off('click', '.btn-pricing');
            
            // اضافه کردن رویداد جدید
            $(document).on('click', '.btn-pricing', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const $btn = $(this);
                const hotelId = $btn.data('hotel-id');
                const roomIndex = $btn.data('room-index');
                
                console.log('>>> دکمه قیمت‌گذاری کلیک شد!');
                console.log('    هتل ID:', hotelId);
                console.log('    اتاق Index:', roomIndex);
                
                if (!hotelId || roomIndex === undefined) {
                    console.error('✗ داده‌های نامعتبر!');
                    alert('خطا: داده‌های اتاق نامعتبر است');
                    return;
                }
                
                Manager.openPricingModal(hotelId, roomIndex);
            });
        },
        
        loadCategories: function() {
            $.post(wchrManager.ajaxurl, {
                action: 'wchr_get_categories',
                nonce: wchrManager.nonce
            }, (response) => {
                if (response.success) {
                    let options = '<option value="">همه</option>';
                    response.data.forEach(cat => {
                        options += `<option value="${cat.id}">${cat.name} (${cat.count})</option>`;
                    });
                    $('#filter-category').html(options);
                }
            });
        },
        
        loadHotels: function() {
            console.log('→ بارگذاری هتل‌ها...');
            $('#hotels-list').html('<div class="wchr-loading"><p>در حال بارگذاری</p></div>');
            
            $.post(wchrManager.ajaxurl, {
                action: 'wchr_get_hotels',
                nonce: wchrManager.nonce,
                search: $('#hotel-search').val(),
                rating: $('#filter-rating').val(),
                category: $('#filter-category').val()
            }, (response) => {
                if (response.success) {
                    console.log('✓ دریافت', response.data.length, 'هتل');
                    this.displayHotels(response.data);
                } else {
                    console.error('✗ خطا در دریافت هتل‌ها');
                    $('#hotels-list').html('<div class="no-hotels"><h3>خطا در بارگذاری</h3></div>');
                }
            }).fail(function(xhr, status, error) {
                console.error('✗ خطای AJAX:', status, error);
                $('#hotels-list').html('<div class="no-hotels"><h3>خطا در ارتباط با سرور</h3></div>');
            });
        },
        
        displayHotels: function(hotels) {
            if (hotels.length === 0) {
                $('#hotels-list').html(`
                    <div class="no-hotels">
                        <h3>🏨 هنوز هتلی تعریف نشده است</h3>
                        <p>برای شروع، یک محصول جدید ایجاد کنید و سیستم رزرو را فعال کنید.</p>
                        <a href="post-new.php?post_type=product" class="button button-primary">افزودن هتل جدید</a>
                    </div>
                `);
                return;
            }
            
            let html = '';
            hotels.forEach(hotel => {
                const statusClass = hotel.status === 'publish' ? 'publish' : 'draft';
                const statusText = hotel.status === 'publish' ? 'منتشر شده' : 'پیش‌نویس';
                const rating = this.formatRating(hotel.rating);
                
                html += `
                    <div class="hotel-card" data-hotel-id="${hotel.id}">
                        <div class="hotel-header">
                            <div>
                                <h3 class="hotel-title">${hotel.title}</h3>
                                <span class="hotel-status status-${statusClass}">${statusText}</span>
                            </div>
                            <div class="hotel-rating">${rating}</div>
                        </div>
                        
                        <div class="hotel-meta">
                            <div class="meta-item">
                                <span class="meta-label">تعداد اتاق</span>
                                <span class="meta-value">${hotel.rooms_count}</span>
                            </div>
                        </div>
                        
                        ${hotel.categories ? `<div class="hotel-categories"><strong>دسته‌بندی:</strong> ${hotel.categories}</div>` : ''}
                        
                        <div class="rooms-list">
                            ${this.displayRooms(hotel)}
                        </div>
                        
                        <div style="margin-top:15px;padding-top:15px;border-top:1px solid #f0f0f0;">
                            <a href="post.php?post=${hotel.id}&action=edit" class="button" target="_blank">✏️ ویرایش هتل</a>
                        </div>
                    </div>
                `;
            });
            
            $('#hotels-list').html(html);
            console.log('✓ هتل‌ها نمایش داده شدند');
        },
        
        displayRooms: function(hotel) {
            if (hotel.rooms.length === 0) {
                return '<p style="text-align:center;color:#999;padding:15px;">اتاقی وجود ندارد</p>';
            }
            
            let html = '';
            hotel.rooms.forEach(room => {
                const priceCount = room.daily_prices_count || 0;
                const priceStatus = priceCount > 0 ? `<small>(${priceCount} روز قیمت‌گذاری شده)</small>` : '';
                
                html += `
                    <div class="room-item">
                        <div class="room-info">
                            <div class="room-name">🚪 ${room.name}</div>
                            <div class="room-details">👥 ظرفیت: ${room.capacity} نفر ${priceStatus}</div>
                        </div>
                        <div class="room-price">${room.base_price} تومان</div>
                        <div class="room-actions">
                            <button type="button" class="btn-pricing button button-primary" data-hotel-id="${hotel.id}" data-room-index="${room.index}">
                                📅 قیمت‌گذاری
                            </button>
                        </div>
                    </div>
                `;
            });
            
            return html;
        },
        
        formatRating: function(rating) {
            if (!rating) return '';
            if (isNaN(rating)) return rating;
            const stars = parseInt(rating);
            return '⭐'.repeat(Math.max(1, Math.min(5, stars)));
        },
        
        setupModal: function() {
            console.log('✓ تنظیم مودال');
            
            $(document).on('click', '.modal-close', (e) => {
                e.preventDefault();
                this.closeModal();
            });
            
            $(document).on('click', '#pricing-modal', (e) => {
                if ($(e.target).is('#pricing-modal')) {
                    this.closeModal();
                }
            });
            
            $(document).on('click', '#save-prices', (e) => {
                e.preventDefault();
                this.savePrices();
            });
            
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && $('#pricing-modal').is(':visible')) {
                    this.closeModal();
                }
            });
        },
        
        setupCalendar: function() {
            $(document).on('click', '#prev-month', (e) => {
                e.preventDefault();
                this.currentMonth--;
                if (this.currentMonth < 1) {
                    this.currentMonth = 12;
                    this.currentYear--;
                }
                this.renderCalendar();
            });
            
            $(document).on('click', '#next-month', (e) => {
                e.preventDefault();
                this.currentMonth++;
                if (this.currentMonth > 12) {
                    this.currentMonth = 1;
                    this.currentYear++;
                }
                this.renderCalendar();
            });
        },
        
        openPricingModal: function(hotelId, roomIndex) {
            console.log('>>> باز کردن مودال قیمت‌گذاری');
            console.log('    هتل:', hotelId, '| اتاق:', roomIndex);
            
            const $modal = $('#pricing-modal');
            if ($modal.length === 0) {
                console.error('✗ مودال در DOM یافت نشد!');
                alert('خطا: مودال یافت نشد. لطفاً صفحه را رفرش کنید.');
                return;
            }
            
            $.ajax({
                url: wchrManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wchr_get_rooms',
                    nonce: wchrManager.nonce,
                    hotel_id: hotelId,
                    room_index: roomIndex
                },
                beforeSend: function() {
                    console.log('→ ارسال درخواست به سرور...');
                },
                success: (response) => {
                    console.log('✓ پاسخ دریافت شد:', response);
                    
                    if (response.success) {
                        this.currentHotel = response.data.hotel_id;
                        this.currentRoom = response.data.room_index;
                        this.currentRoomData = response.data.room;
                        this.priceChanges = {};
                        
                        $('#modal-hotel-name').text(response.data.hotel_title);
                        $('#modal-room-name').text(response.data.room.name);
                        $('#modal-base-price').text(this.formatNumber(response.data.room.base_price));
                        
                        this.renderCalendar();
                        
                        console.log('✓ نمایش مودال...');
                        $modal.fadeIn(300);
                    } else {
                        console.error('✗ خطا:', response);
                        alert('خطا: ' + (response.data ? response.data.message : 'مشکلی پیش آمده'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('✗ خطای AJAX:');
                    console.error('   Status:', status);
                    console.error('   Error:', error);
                    console.error('   Response:', xhr.responseText);
                    alert('خطا در ارتباط با سرور. لطفاً Console مرورگر را بررسی کنید (F12)');
                }
            });
        },
        
        closeModal: function() {
            console.log('← بستن مودال');
            $('#pricing-modal').fadeOut(300);
            this.currentHotel = null;
            this.currentRoom = null;
            this.currentRoomData = null;
            this.priceChanges = {};
        },
        
        renderCalendar: function() {
            const monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 
                               'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
            
            $('#current-month').text(`${monthNames[this.currentMonth]} ${this.currentYear}`);
            
            const daysInMonth = this.getDaysInMonth(this.currentYear, this.currentMonth);
            const today = new Date();
            const todayJalali = this.gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
            
            let html = '';
            
            // روزهای هفته
            const weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
            weekDays.forEach(day => {
                html += `<div style="text-align:center;font-weight:bold;padding:10px;color:#666;">${day}</div>`;
            });
            
            // روزهای ماه
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${this.currentYear}/${String(this.currentMonth).padStart(2, '0')}/${String(day).padStart(2, '0')}`;
                const isPast = this.isDatePast(this.currentYear, this.currentMonth, day, todayJalali);
                
                let price = '';
                let hasPrice = false;
                
                if (this.currentRoomData && this.currentRoomData.daily_prices && this.currentRoomData.daily_prices[dateStr]) {
                    price = this.currentRoomData.daily_prices[dateStr];
                    hasPrice = true;
                }
                
                if (this.priceChanges[dateStr] !== undefined) {
                    price = this.priceChanges[dateStr];
                    hasPrice = price > 0;
                }
                
                const pastClass = isPast ? 'past' : '';
                const priceClass = hasPrice ? 'has-price' : '';
                
                html += `
                    <div class="calendar-day ${pastClass} ${priceClass}" data-date="${dateStr}">
                        <div class="day-number">${this.toPersianNumber(day)}</div>
                        <div class="day-price">
                            ${isPast ? 
                                '<small>گذشته</small>' : 
                                `<input type="text" 
                                    class="price-input" 
                                    placeholder="${this.formatNumber(this.currentRoomData.base_price)}"
                                    value="${price ? this.formatNumber(price) : ''}"
                                    data-date="${dateStr}">`
                            }
                        </div>
                    </div>
                `;
            }
            
            $('#pricing-calendar').html(html);
            
            // رویداد تغییر قیمت
            $(document).off('input', '.price-input').on('input', '.price-input', (e) => {
                const $input = $(e.target);
                const date = $input.data('date');
                const value = $input.val().replace(/,/g, '');
                Manager.priceChanges[date] = value ? parseFloat(value) : 0;
            });
        },
        
        getDaysInMonth: function(year, month) {
            if (month <= 6) return 31;
            if (month <= 11) return 30;
            return this.isLeapYear(year) ? 30 : 29;
        },
        
        isLeapYear: function(year) {
            const breaks = [1, 5, 9, 13, 17, 22, 26, 30];
            const gy = year + 621;
            let jp = breaks[0];
            
            let jump = 0;
            for (let i = 1; i < breaks.length; i++) {
                const jm = breaks[i];
                jump = jm - jp;
                if (year < jm) break;
                jp = jm;
            }
            
            let n = year - jp;
            
            if (jump - n < 6) n = n - jump + (jump + 4) % 33;
            
            return ((n + 1) % 33) < 5;
        },
        
        isDatePast: function(year, month, day, today) {
            if (year < today[0]) return true;
            if (year > today[0]) return false;
            if (month < today[1]) return true;
            if (month > today[1]) return false;
            return day < today[2];
        },
        
        savePrices: function() {
            if (Object.keys(this.priceChanges).length === 0) {
                alert('هیچ تغییری ایجاد نشده است');
                return;
            }
            
            console.log('→ ذخیره', Object.keys(this.priceChanges).length, 'قیمت');
            
            const $btn = $('#save-prices');
            $btn.prop('disabled', true).text('در حال ذخیره...');
            
            $.post(wchrManager.ajaxurl, {
                action: 'wchr_save_prices',
                nonce: wchrManager.nonce,
                hotel_id: this.currentHotel,
                room_index: this.currentRoom,
                prices: this.priceChanges
            }, (response) => {
                $btn.prop('disabled', false).text('💾 ذخیره همه تغییرات');
                
                if (response.success) {
                    console.log('✓ قیمت‌ها ذخیره شدند');
                    alert('✓ ' + response.data.message);
                    this.closeModal();
                    this.loadHotels();
                } else {
                    console.error('✗ خطا در ذخیره:', response);
                    alert('✗ ' + (response.data ? response.data.message : 'خطا در ذخیره'));
                }
            }).fail(function(xhr, status, error) {
                console.error('✗ خطای ذخیره:', status, error);
                $btn.prop('disabled', false).text('💾 ذخیره همه تغییرات');
                alert('خطا در ارتباط با سرور');
            });
        },
        
        gregorianToJalali: function(gy, gm, gd) {
            const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            let jy = (gy > 1600) ? 979 : 0;
            gy = (gy > 1600) ? gy - 1600 : gy - 621;
            const gy2 = (gm > 2) ? (gy + 1) : gy;
            let days = (365 * gy) + (Math.floor((gy2 + 3) / 4)) - (Math.floor((gy2 + 99) / 100)) + 
                      (Math.floor((gy2 + 399) / 400)) - 80 + gd + g_d_m[gm - 1];
            jy += 33 * Math.floor(days / 12053);
            days %= 12053;
            jy += 4 * Math.floor(days / 1461);
            days %= 1461;
            if (days > 365) {
                jy += Math.floor((days - 1) / 365);
                days = (days - 1) % 365;
            }
            const jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
            const jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
            return [jy, jm, jd];
        },
        
        formatNumber: function(num) {
            return new Intl.NumberFormat('fa-IR').format(num);
        },
        
        toPersianNumber: function(num) {
            const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            return String(num).replace(/\d/g, x => persian[x]);
        }
    };
    
    // راه‌اندازی
    $(document).ready(function() {
        console.log('=== Hotel Manager Starting ===');
        console.log('jQuery:', $.fn.jquery);
        console.log('wchrManager:', typeof wchrManager !== 'undefined' ? '✓' : '✗');
        
        if ($('.wchr-manager').length) {
            console.log('✓ صفحه مدیریت یافت شد');
            Manager.init();
        } else {
            console.log('ℹ این صفحه، صفحه مدیریت هتل نیست');
        }
    });
    
})(jQuery);
