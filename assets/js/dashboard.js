// Updated Dashboard JavaScript with better error handling
class ReaderDashboard {
    constructor() {
        this.currentSection = 'library';
        this.currentView = 'grid';
        this.currentFilter = 'all';
        this.currentAlphabet = 'all';
        this.searchTimeout = null;
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadLibraryBooks();
        this.loadNotifications();
        this.loadUserStats();
        console.log('📚 Reader Dashboard initialized successfully!');
    }

    bindEvents() {
        // Search inputs
        document.getElementById('librarySearchInput')?.addEventListener('input', (e) => {
            this.handleSearch(e.target.value, 'library');
        });

        document.getElementById('myBooksSearchInput')?.addEventListener('input', (e) => {
            this.handleSearch(e.target.value, 'myBooks');
        });

        document.getElementById('historySearchInput')?.addEventListener('input', (e) => {
            this.handleSearch(e.target.value, 'history');
        });

        document.getElementById('favoritesSearchInput')?.addEventListener('input', (e) => {
            this.handleSearch(e.target.value, 'favorites');
        });

        // View toggle
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.changeView(e.target.dataset.view);
            });
        });

        // Profile form
        document.getElementById('profileForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.updateProfile();
        });

        // Borrow confirmation
        document.getElementById('confirmBorrowBtn')?.addEventListener('click', () => {
            this.confirmBorrow();
        });
    }

    // Section Management
    showSection(sectionName) {
        this.showLoading();

        // Update navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelector(`[data-section="${sectionName}"]`)?.classList.add('active');

        // Hide all sections
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });

        // Show target section
        setTimeout(() => {
            const targetSection = document.getElementById(`${sectionName}-section`);
            if (targetSection) {
                targetSection.classList.add('active');
                this.currentSection = sectionName;
                this.loadSectionData(sectionName);
            }
            this.hideLoading();
        }, 300);
    }

    loadSectionData(section) {
        switch (section) {
            case 'library':
                this.loadLibraryBooks();
                break;
            case 'my-books':
                this.loadMyBooks();
                break;
            case 'history':
                this.loadHistory();
                break;
            case 'favorites':
                this.loadFavorites();
                break;
            case 'notifications':
                this.loadNotifications();
                break;
        }
    }

    // Loading Management
    showLoading() {
        document.getElementById('loadingOverlay')?.classList.remove('d-none');
    }

    hideLoading() {
        document.getElementById('loadingOverlay')?.classList.add('d-none');
    }

    // Library Books
    async loadLibraryBooks() {
        try {
            console.log('Loading library books...');
            
            const params = new URLSearchParams({
                action: 'library',
                filter: this.currentFilter,
                alphabet: this.currentAlphabet,
                search: document.getElementById('librarySearchInput')?.value || ''
            });
            
            const response = await fetch(`api/get_books.php?${params}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                console.log('Books loaded:', data.books.length);
                this.renderBooks(data.books, 'booksGrid');
            } else {
                console.error('API Error:', data.message);
                this.showError(data.message || 'Failed to load books');
            }
        } catch (error) {
            console.error('Error loading books:', error);
            this.showError('Error loading books. Please check your connection.');
            
            // Show fallback content
            const container = document.getElementById('booksGrid');
            if (container) {
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <h5>Unable to load books</h5>
                            <p>Please check your internet connection and try again.</p>
                            <button class="btn btn-primary" onclick="window.dashboard.loadLibraryBooks()">
                                <i class="fas fa-refresh me-2"></i>Retry
                            </button>
                        </div>
                    </div>
                `;
            }
        }
    }

    renderBooks(books, containerId) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error('Container not found:', containerId);
            return;
        }

        if (books.length === 0) {
            container.innerHTML = `
                <div class="col-12">
                    <div class="no-results text-center py-5">
                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No books found</h5>
                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                    </div>
                </div>
            `;
            return;
        }

        container.innerHTML = books.map(book => `
            <div class="book-card" onclick="showBookDetails(${book.book_id})">
                <div class="book-cover">
                    <img src="${book.cover_image}" 
                         alt="${book.title}" 
                         loading="lazy"
                         onerror="this.src='assets/images/default-book.jpg'">
                    <div class="book-status ${book.available_copies > 0 ? 'available' : 'borrowed'}">
                        ${book.available_copies > 0 ? 'Available' : 'Borrowed'}
                    </div>
                </div>
                <div class="book-info">
                    <h6 class="book-title">${book.title}</h6>
                    <p class="book-author">by ${book.author}</p>
                    <div class="book-rating mb-2">
                        ${this.renderStars(book.rating)}
                        <small class="text-muted ms-1">(${book.rating})</small>
                    </div>
                    <div class="book-actions">
                        <button class="btn-favorite ${book.is_favorite ? 'active' : ''}" 
                                onclick="event.stopPropagation(); toggleFavorite(${book.book_id})"
                                title="${book.is_favorite ? 'Remove from favorites' : 'Add to favorites'}">
                            <i class="fas fa-heart"></i>
                        </button>
                        <button class="btn-borrow" 
                                onclick="event.stopPropagation(); borrowBook(${book.book_id})"
                                ${book.available_copies === 0 ? 'disabled' : ''}
                                title="${book.available_copies > 0 ? 'Borrow this book' : 'Currently unavailable'}">
                            ${book.available_copies > 0 ? 'Borrow' : 'Unavailable'}
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    renderStars(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 !== 0;
        let starsHtml = '';
        
        for (let i = 0; i < fullStars; i++) {
            starsHtml += '<i class="fas fa-star text-warning"></i>';
        }
        
        if (hasHalfStar) {
            starsHtml += '<i class="fas fa-star-half-alt text-warning"></i>';
        }
        
        const emptyStars = 5 - Math.ceil(rating);
        for (let i = 0; i < emptyStars; i++) {
            starsHtml += '<i class="far fa-star text-muted"></i>';
        }
        
        return starsHtml;
    }

    // My Books
    async loadMyBooks() {
        try {
            const response = await fetch(`api/get_books.php?action=my_books&search=${document.getElementById('myBooksSearchInput')?.value || ''}`);
            const data = await response.json();
            
            if (data.success) {
                this.renderMyBooks(data.loans);
            } else {
                this.showError('Failed to load your books');
            }
        } catch (error) {
            console.error('Error loading my books:', error);
            this.showError('Error loading your books');
        }
    }

    renderMyBooks(loans) {
        const container = document.getElementById('myBooksContainer');
        if (!container) return;

        if (loans.length === 0) {
            container.innerHTML = `
                <div class="no-results text-center py-5">
                    <i class="fas fa-bookmark fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No borrowed books</h5>
                    <p class="text-muted">You haven't borrowed any books yet</p>
                    <button class="btn btn-primary" onclick="showSection('library')">Browse Library</button>
                </div>
            `;
            return;
        }

        // Separate pending and active loans
        const pendingLoans = loans.filter(loan => loan.approval_status === 'pending');
        const activeLoans = loans.filter(loan => loan.approval_status === 'approved');

        let html = '';

        if (pendingLoans.length > 0) {
            html += `
                <div class="section-divider mb-4">
                    <h5 class="text-warning">
                        <i class="fas fa-clock me-2"></i>Pending Approval (${pendingLoans.length})
                    </h5>
                </div>
            `;
            html += pendingLoans.map(loan => this.renderLoanCard(loan)).join('');
        }

        if (activeLoans.length > 0) {
            html += `
                <div class="section-divider mb-4 ${pendingLoans.length > 0 ? 'mt-5' : ''}">
                    <h5 class="text-primary">
                        <i class="fas fa-check me-2"></i>Active Loans (${activeLoans.length})
                    </h5>
                </div>
            `;
            html += activeLoans.map(loan => this.renderLoanCard(loan)).join('');
        }

        container.innerHTML = html;
    }

    renderLoanCard(loan) {
        const isOverdue = loan.status === 'active' && new Date(loan.due_date) < new Date();
        const statusClass = loan.approval_status === 'pending' ? 'pending' : 
                           isOverdue ? 'overdue' : 'active';
        
        return `
            <div class="loan-card" onclick="showBookDetails(${loan.book_id})">
                <div class="loan-book-cover">
                    <img src="${loan.cover_image}" 
                         alt="${loan.title}"
                         onerror="this.src='assets/images/default-book.jpg'">
                </div>
                <div class="loan-details">
                    <h6 class="loan-title">${loan.title}</h6>
                    <p class="loan-author">by ${loan.author}</p>
                    <div class="loan-dates">
                        <div><strong>Borrowed:</strong> ${this.formatDate(loan.loan_date)}</div>
                        <div><strong>Due:</strong> ${this.formatDate(loan.due_date)}</div>
                        ${isOverdue ? '<div class="text-danger"><strong>OVERDUE!</strong></div>' : ''}
                        ${loan.fine_amount > 0 ? `<div class="text-warning"><strong>Fine: $${loan.fine_amount}</strong></div>` : ''}
                    </div>
                </div>
                <div class="loan-status">
                    <span class="status-badge ${statusClass}">
                        ${loan.approval_status === 'pending' ? 'Pending' : 
                          isOverdue ? 'Overdue' : 'Active'}
                    </span>
                </div>
            </div>
        `;
    }

    // Search functionality
    handleSearch(query, section) {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            switch (section) {
                case 'library':
                    this.loadLibraryBooks();
                    break;
                case 'myBooks':
                    this.loadMyBooks();
                    break;
                case 'history':
                    this.loadHistory();
                    break;
                case 'favorites':
                    this.loadFavorites();
                    break;
            }
        }, 300);
    }

    // Filter functions
    filterBooks(filter) {
        this.currentFilter = filter;
        this.loadLibraryBooks();
    }

    filterByAlphabet(letter) {
        this.currentAlphabet = letter;
        document.querySelectorAll('.alphabet-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.classList.add('active');
        this.loadLibraryBooks();
    }

    changeView(view) {
        this.currentView = view;
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.classList.add('active');
        
        const container = document.getElementById('booksGrid');
        if (view === 'list') {
            container.classList.add('list-view');
        } else {
            container.classList.remove('list-view');
        }
    }

    // Book actions
    async borrowBook(bookId) {
        // Check membership status first
        const membershipExpired = document.querySelector('.membership-alert');
        if (membershipExpired) {
            this.showError('Please renew your membership to borrow books');
            return;
        }

        this.currentBookId = bookId;
        const modal = new bootstrap.Modal(document.getElementById('borrowConfirmModal'));
        modal.show();
    }

    async confirmBorrow() {
        try {
            const response = await fetch('api/borrow_book.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    book_id: this.currentBookId
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess('Book reservation submitted! Please wait for approval.');
                bootstrap.Modal.getInstance(document.getElementById('borrowConfirmModal')).hide();
                this.loadLibraryBooks();
                this.loadNotifications(); // Refresh notifications
            } else {
                this.showError(data.message || 'Failed to reserve book');
            }
        } catch (error) {
            console.error('Error borrowing book:', error);
            this.showError('Error processing request');
        }
    }

    async toggleFavorite(bookId) {
        try {
            const response = await fetch('api/toggle_favorite.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    book_id: bookId
                })
            });

            const data = await response.json();

            if (data.success) {
                this.loadLibraryBooks();
                if (this.currentSection === 'favorites') {
                    this.loadFavorites();
                }
                this.showSuccess(`Book ${data.action} ${data.action === 'added' ? 'to' : 'from'} favorites`);
            } else {
                this.showError('Failed to update favorites');
            }
        } catch (error) {
            console.error('Error toggling favorite:', error);
            this.showError('Error updating favorites');
        }
    }

    // Load other sections
    async loadHistory() {
        try {
            const response = await fetch(`api/get_books.php?action=history&search=${document.getElementById('historySearchInput')?.value || ''}`);
            const data = await response.json();
            
            const container = document.getElementById('historyContainer');
            if (container) {
                if (data.success && data.history && data.history.length > 0) {
                    container.innerHTML = data.history.map(item => this.renderHistoryItem(item)).join('');
                } else {
                    container.innerHTML = `
                        <div class="text-center py-5">
                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Reading History</h5>
                            <p class="text-muted">Your reading history will appear here once you return books</p>
                        </div>
                    `;
                }
            }
        } catch (error) {
            console.error('Error loading history:', error);
        }
    }

    async loadFavorites() {
        try {
            const response = await fetch(`api/get_books.php?action=favorites&search=${document.getElementById('favoritesSearchInput')?.value || ''}`);
            const data = await response.json();
            
            const container = document.getElementById('favoritesContainer');
            if (container) {
                if (data.success && data.favorites && data.favorites.length > 0) {
                    container.innerHTML = `
                        <div class="books-grid">
                            ${data.favorites.map(book => `
                                <div class="book-card" onclick="showBookDetails(${book.book_id})">
                                    <div class="book-cover">
                                        <img src="${book.cover_image}" alt="${book.title}" onerror="this.src='assets/images/default-book.jpg'">
                                        <div class="book-status ${book.available_copies > 0 ? 'available' : 'borrowed'}">
                                            ${book.available_copies > 0 ? 'Available' : 'Borrowed'}
                                        </div>
                                    </div>
                                    <div class="book-info">
                                        <h6 class="book-title">${book.title}</h6>
                                        <p class="book-author">by ${book.author}</p>
                                        <div class="book-actions">
                                            <button class="btn-favorite active" 
                                                    onclick="event.stopPropagation(); toggleFavorite(${book.book_id})"
                                                    title="Remove from favorites">
                                                <i class="fas fa-heart"></i>
                                            </button>
                                            <button class="btn-borrow" 
                                                    onclick="event.stopPropagation(); borrowBook(${book.book_id})"
                                                    ${book.available_copies === 0 ? 'disabled' : ''}>
                                                ${book.available_copies > 0 ? 'Borrow' : 'Unavailable'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `;
                } else {
                    container.innerHTML = `
                        <div class="text-center py-5">
                            <i class="fas fa-heart fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Favorite Books</h5>
                            <p class="text-muted">Add books to your favorites by clicking the heart icon</p>
                            <button class="btn btn-primary" onclick="showSection('library')">Browse Library</button>
                        </div>
                    `;
                }
            }
        } catch (error) {
            console.error('Error loading favorites:', error);
        }
    }

    async loadNotifications() {
        try {
            const response = await fetch('api/get_notifications.php');
            const data = await response.json();
            
            if (data.success) {
                this.renderNotifications(data.notifications);
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }

    renderNotifications(notifications) {
        const container = document.getElementById('notificationsContainer');
        if (!container) return;

        if (notifications.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-bell fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No notifications</h5>
                    <p class="text-muted">You're all caught up!</p>
                </div>
            `;
            return;
        }

        container.innerHTML = notifications.map(notif => `
            <div class="notification-item ${!notif.is_read ? 'unread' : ''}" 
                 onclick="markAsRead(${notif.notification_id})">
                <div class="notification-icon ${notif.type}">
                    <i class="fas fa-${this.getNotificationIcon(notif.type)}"></i>
                </div>
                <div class="notification-content">
                    <h6 class="notification-title">${notif.title}</h6>
                    <p class="notification-message">${notif.message}</p>
                    <div class="notification-time">${this.formatDateTime(notif.created_at)}</div>
                </div>
            </div>
        `).join('');
    }

    async loadUserStats() {
        try {
            const response = await fetch('api/get_user_stats.php');
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('totalBorrowed').textContent = data.stats.total_borrowed || 0;
                document.getElementById('totalRead').textContent = data.stats.total_read || 0;
                document.getElementById('totalFavorites').textContent = data.stats.total_favorites || 0;
            }
        } catch (error) {
            console.error('Error loading user stats:', error);
        }
    }

    // Utility functions
    formatDate(dateString) {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    formatDateTime(dateString) {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    getNotificationIcon(type) {
        const icons = {
            'info': 'info-circle',
            'success': 'check-circle',
            'warning': 'exclamation-triangle',
            'danger': 'exclamation-circle'
        };
        return icons[type] || 'bell';
    }

    showSuccess(message) {
        this.showNotification(message, 'success');
    }

    showError(message) {
        this.showNotification(message, 'danger');
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 2rem; right: 2rem; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${this.getNotificationIcon(type)} me-2"></i>
                <div class="flex-grow-1">${message}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    async updateProfile() {
        this.showSuccess('Profile updated successfully!');
    }

    async markAllAsRead() {
        try {
            const response = await fetch('api/mark_notifications_read.php', {
                method: 'POST'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.loadNotifications();
                this.showSuccess('All notifications marked as read');
            }
        } catch (error) {
            console.error('Error marking notifications as read:', error);
        }
    }

    async markAsRead(notificationId) {
        try {
            const response = await fetch('api/mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    notification_id: notificationId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.loadNotifications();
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

    contactLibrary() {
        this.showNotification('Please contact the library at +1 (555) 123-4567 or visit in person to renew your membership.', 'info');
    }
}

// Global functions for onclick events
function showSection(section) {
    window.dashboard.showSection(section);
}

function showBookDetails(bookId) {
    console.log('Show book details for:', bookId);
    // Implementation for showing book details modal
}

function borrowBook(bookId) {
    window.dashboard.borrowBook(bookId);
}

function toggleFavorite(bookId) {
    window.dashboard.toggleFavorite(bookId);
}

function filterBooks(filter) {
    window.dashboard.filterBooks(filter);
}

function filterByAlphabet(letter) {
    window.dashboard.filterByAlphabet(letter);
}

function filterMyBooks(filter) {
    console.log('Filter my books:', filter);
}

function markAllAsRead() {
    window.dashboard.markAllAsRead();
}

function markAsRead(notificationId) {
    window.dashboard.markAsRead(notificationId);
}

function resetProfileForm() {
    document.getElementById('profileForm').reset();
}

function contactLibrary() {
    window.dashboard.contactLibrary();
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.dashboard = new ReaderDashboard();
});

console.log('🚀 Dashboard JavaScript loaded successfully!');
