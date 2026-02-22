// Enhanced Universal ABC Library Chatbot
class UniversalChatbot {
    constructor() {
        this.isOpen = false;
        this.messages = [];
        this.pageContext = window.chatbotPageContext || 'general';
        this.membershipStatus = window.membershipConfig || null;
        this.hasShownGreeting = false;
        this.conversationHistory = [];
        this.isTyping = false;
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadConversationHistory();
        
        // Show context-aware greeting after delay
        setTimeout(() => {
            this.showContextGreeting();
        }, 3000);
    }
    
    bindEvents() {
        const inputField = document.getElementById('chatbotInputField');
        const sendBtn = document.getElementById('chatbotSendBtn');
        
        if (inputField) {
            inputField.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            inputField.addEventListener('input', () => {
                this.removeAutoGreeting();
            });
        }
        
        if (sendBtn) {
            sendBtn.addEventListener('click', () => this.sendMessage());
        }
        
        // Handle quick action buttons
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('quick-action-btn')) {
                const message = e.target.textContent.trim();
                this.sendQuickMessage(message);
            }
        });
    }
    
    showContextGreeting() {
        if (this.hasShownGreeting) return;
        
        this.hasShownGreeting = true;
        const chatWindow = document.getElementById('chatbot-window');
        const chatButton = document.getElementById('chatbot-button');
        
        // Add greeting animation
        chatButton.classList.add('greeting-bounce');
        
        // Open chat window
        chatWindow.classList.add('show');
        chatWindow.classList.add('auto-greeting');
        this.isOpen = true;
        
        // Context-aware greeting message
        setTimeout(() => {
            let greetingMessage = this.getContextGreeting();
            this.addMessage(greetingMessage, 'bot');
            
            // Add quick suggestions based on context
            setTimeout(() => {
                this.showQuickSuggestions();
            }, 1000);
        }, 500);
        
        // Auto-close after 8 seconds if no interaction
        setTimeout(() => {
            if (chatWindow.classList.contains('auto-greeting')) {
                this.closeAutoGreeting();
            }
        }, 8000);
    }
    
    getContextGreeting() {
        switch (this.pageContext) {
            case 'reader_dashboard':
                if (this.membershipStatus && this.membershipStatus.expired) {
                    return "Hi! I noticed your membership has expired. I can help you with renewal information or answer any questions about your account. 😊";
                } else if (this.membershipStatus && this.membershipStatus.daysUntilExpiry <= 7) {
                    return `Hello! Your membership expires in ${this.membershipStatus.daysUntilExpiry} day(s). I can help you with renewal or any other library questions. 📚`;
                }
                return "Hello! I'm here to help you navigate your dashboard, find books, or manage your library account. What can I do for you? 📚";
                
            case 'admin_dashboard':
                return "Hello Admin! I can help you with system management, member queries, or book catalog operations. How can I assist? ⚙️";
                
            case 'landing_page':
                return "Welcome to ABC Library! I can help you learn about our services, find books, or guide you through joining our library. 🏛️";
                
            default:
                return "Hi there! I'm your AI library assistant. I'm here to help with any questions about books, services, or your account. 🤖";
        }
    }
    
    showQuickSuggestions() {
        const suggestions = this.getContextSuggestions();
        if (suggestions.length > 0) {
            const suggestionText = "Here are some things I can help you with:\n" + 
                suggestions.map((s, i) => `${i + 1}. ${s}`).join('\n');
            this.addMessage(suggestionText, 'bot');
        }
    }
    
    getContextSuggestions() {
        switch (this.pageContext) {
            case 'reader_dashboard':
                if (this.membershipStatus && this.membershipStatus.expired) {
                    return [
                        'Get membership renewal help',
                        'Contact library information',
                        'View membership details'
                    ];
                }
                return [
                    'Search for specific books',
                    'Check your borrowing status',
                    'Navigate dashboard sections',
                    'View membership details'
                ];
            case 'landing_page':
                return [
                    'Learn about our services',
                    'Find out how to join',
                    'Search our book catalog',
                    'Get contact information'
                ];
            default:
                return [
                    'Search for books',
                    'Get library information',
                    'Account help'
                ];
        }
    }
    
    async sendMessage() {
        const inputField = document.getElementById('chatbotInputField');
        const message = inputField.value.trim();
        
        if (!message || this.isTyping) return;
        
        // Add user message
        this.addMessage(message, 'user');
        inputField.value = '';
        this.removeAutoGreeting();
        
        // Show typing indicator
        this.showTyping();
        this.isTyping = true;
        
        try {
            // Get the correct API path
            let apiUrl = '';
            if (this.pageContext === 'reader_dashboard') {
                apiUrl = '../../chatbot/chatbot-api.php';
            } else {
                apiUrl = '/abclib/chatbot/chatbot-api.php';
            }
            
            // Send to backend API
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    message: message,
                    page_context: this.pageContext,
                    conversation_history: this.conversationHistory.slice(-5)
                })
            });
            
            const data = await response.json();
            
            this.hideTyping();
            this.isTyping = false;
            
            if (data.success) {
                // Add bot response
                this.addMessage(data.message, 'bot');
                
                // Handle special data (like book search results)
                if (data.data) {
                    this.handleSpecialData(data.data, data.intent);
                }
                
                // Show suggestions if provided
                if (data.suggestions && data.suggestions.length > 0) {
                    setTimeout(() => {
                        this.showSuggestionButtons(data.suggestions);
                    }, 500);
                }
                
                // Update conversation history
                this.conversationHistory.push({
                    user: message,
                    bot: data.message,
                    intent: data.intent,
                    timestamp: Date.now()
                });
                
                // Keep only last 10 conversations
                if (this.conversationHistory.length > 10) {
                    this.conversationHistory = this.conversationHistory.slice(-10);
                }
                
            } else {
                this.addMessage(data.error || 'Sorry, I encountered an error. Please try again.', 'bot');
            }
            
        } catch (error) {
            console.error('Chatbot error:', error);
            this.hideTyping();
            this.isTyping = false;
            this.addMessage('I apologize, but I\'m having connection issues. Please try again in a moment.', 'bot');
        }
    }
    
    sendQuickMessage(message) {
        const inputField = document.getElementById('chatbotInputField');
        if (inputField) {
            inputField.value = message;
            this.sendMessage();
        }
    }
    
    handleSpecialData(data, intent) {
        switch (intent) {
            case 'search_books':
                if (data.book_id) {
                    this.showBookCard(data);
                }
                break;
        }
    }
    
    showBookCard(book) {
        const bookCard = document.createElement('div');
        bookCard.className = 'message bot-message book-card-message';
        
        // Get correct image path
        let imagePath = book.cover_image;
        if (imagePath && !imagePath.startsWith('http')) {
            if (this.pageContext === 'reader_dashboard') {
                imagePath = '../../assets/uploads/book_covers/' + imagePath;
            } else {
                imagePath = '/abclib/assets/uploads/book_covers/' + imagePath;
            }
        }
        if (!imagePath) {
            imagePath = this.pageContext === 'reader_dashboard' ? 
                '../../assets/images/default-book.jpg' : 
                '/abclib/assets/images/default-book.jpg';
        }
        
        bookCard.innerHTML = `
            <div class="message-content">
                <div class="book-preview-card">
                    <div class="book-preview-cover">
                        <img src="${imagePath}" 
                             alt="${book.title}" loading="lazy"
                             onerror="this.src='${this.pageContext === 'reader_dashboard' ? '../../assets/images/default-book.jpg' : '/abclib/assets/images/default-book.jpg'}'">
                    </div>
                    <div class="book-preview-info">
                        <h6 class="book-preview-title">${book.title}</h6>
                        <p class="book-preview-author">by ${book.author}</p>
                        <div class="book-preview-status">
                            <span class="status-badge ${book.available_copies > 0 ? 'available' : 'borrowed'}">
                                ${book.available_copies > 0 ? 'Available' : 'Not Available'}
                            </span>
                        </div>
                        ${this.pageContext === 'reader_dashboard' && book.available_copies > 0 && this.membershipStatus && !this.membershipStatus.expired ? 
                            '<button class="btn btn-sm btn-primary mt-2" onclick="borrowBookFromChat(' + book.book_id + ')">Borrow Book</button>' : 
                            this.membershipStatus && this.membershipStatus.expired ? 
                            '<small class="text-muted">Renew membership to borrow</small>' : ''
                        }
                    </div>
                </div>
                <small class="message-time">${this.getCurrentTime()}</small>
            </div>
        `;
        
        const messagesContainer = document.getElementById('chatbotMessages');
        messagesContainer.appendChild(bookCard);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    showSuggestionButtons(suggestions) {
        const suggestionDiv = document.createElement('div');
        suggestionDiv.className = 'message bot-message suggestion-message';
        suggestionDiv.innerHTML = `
            <div class="message-content">
                <div class="suggestion-buttons">
                    ${suggestions.map(suggestion => 
                        `<button class="suggestion-btn" onclick="window.universalChatbot.sendQuickMessage('${suggestion}')">${suggestion}</button>`
                    ).join('')}
                </div>
                <small class="message-time">${this.getCurrentTime()}</small>
            </div>
        `;
        
        const messagesContainer = document.getElementById('chatbotMessages');
        messagesContainer.appendChild(suggestionDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Auto-remove suggestions after 30 seconds
        setTimeout(() => {
            if (suggestionDiv.parentNode) {
                suggestionDiv.remove();
            }
        }, 30000);
    }
    
    addMessage(content, sender) {
        const messagesContainer = document.getElementById('chatbotMessages');
        if (!messagesContainer) return;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}-message`;
        messageDiv.innerHTML = `
            <div class="message-content">
                <p>${content.replace(/\n/g, '<br>')}</p>
                <small class="message-time">${this.getCurrentTime()}</small>
            </div>
        `;
        
        messageDiv.classList.add('message-enter');
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        setTimeout(() => {
            messageDiv.classList.remove('message-enter');
        }, 300);
    }
    
    showTyping() {
        const messagesContainer = document.getElementById('chatbotMessages');
        if (!messagesContainer) return;
        
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message bot-message typing-message';
        typingDiv.innerHTML = `
            <div class="message-content">
                <div class="typing-indicator">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(typingDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    hideTyping() {
        const typingMessage = document.querySelector('.typing-message');
        if (typingMessage) {
            typingMessage.remove();
        }
    }
    
    getCurrentTime() {
        return new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }
    
    closeAutoGreeting() {
        const chatWindow = document.getElementById('chatbot-window');
        const chatButton = document.getElementById('chatbot-button');
        const badge = document.getElementById('chatbotNotificationBadge');
        
        chatWindow.classList.remove('show');
        chatWindow.classList.remove('auto-greeting');
        chatButton.classList.remove('greeting-bounce');
        this.isOpen = false;
        
        // Add notification badge
        chatButton.classList.add('has-notification');
        if (badge) {
            badge.classList.add('show');
            badge.textContent = '1';
        }
        
        setTimeout(() => {
            chatButton.classList.remove('has-notification');
            if (badge) {
                badge.classList.remove('show');
            }
        }, 5000);
    }
    
    removeAutoGreeting() {
        const chatWindow = document.getElementById('chatbot-window');
        const badge = document.getElementById('chatbotNotificationBadge');
        
        if (chatWindow.classList.contains('auto-greeting')) {
            chatWindow.classList.remove('auto-greeting');
            if (badge) {
                badge.classList.remove('show');
            }
        }
    }
    
    loadConversationHistory() {
        // Load recent conversation from localStorage if available
        const stored = localStorage.getItem('chatbot_history');
        if (stored) {
            try {
                this.conversationHistory = JSON.parse(stored);
            } catch (e) {
                console.warn('Failed to load conversation history');
            }
        }
    }
    
    saveConversationHistory() {
        try {
            localStorage.setItem('chatbot_history', JSON.stringify(this.conversationHistory));
        } catch (e) {
            console.warn('Failed to save conversation history');
        }
    }
}

// Global functions
function toggleChatbot() {
    const window = document.getElementById('chatbot-window');
    const button = document.getElementById('chatbot-button');
    const badge = document.getElementById('chatbotNotificationBadge');
    
    if (window.classList.contains('show')) {
        window.classList.remove('show');
        window.classList.remove('auto-greeting');
        if (window.universalChatbot) {
            window.universalChatbot.isOpen = false;
        }
    } else {
        window.classList.add('show');
        if (badge) {
            badge.classList.remove('show');
        }
        if (window.universalChatbot) {
            window.universalChatbot.isOpen = true;
        }
        
        setTimeout(() => {
            const input = document.getElementById('chatbotInputField');
            if (input) input.focus();
        }, 300);
    }
}

function sendMessage() {
    if (window.universalChatbot) {
        window.universalChatbot.sendMessage();
    }
}

function quickMessage(message) {
    if (window.universalChatbot) {
        window.universalChatbot.sendQuickMessage(message);
    }
    
    const chatWindow = document.getElementById('chatbot-window');
    if (!chatWindow.classList.contains('show')) {
        toggleChatbot();
    }
}

// Integration function for dashboard book borrowing
function borrowBookFromChat(bookId) {
    if (window.dashboard && typeof window.dashboard.borrowBook === 'function') {
        window.dashboard.borrowBook(bookId);
        // Send success message to chat
        setTimeout(() => {
            window.universalChatbot.addMessage('Great! I\'ve opened the borrow confirmation for you. 📚', 'bot');
        }, 500);
    } else if (typeof borrowBook === 'function') {
        borrowBook(bookId);
        setTimeout(() => {
            window.universalChatbot.addMessage('Great! I\'ve opened the borrow confirmation for you. 📚', 'bot');
        }, 500);
    } else {
        window.universalChatbot.addMessage('Sorry, I couldn\'t process the borrowing request. Please try using the Borrow button in the Library section.', 'bot');
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.universalChatbot = new UniversalChatbot();
});

// Save conversation history before page unload
window.addEventListener('beforeunload', function() {
    if (window.universalChatbot) {
        window.universalChatbot.saveConversationHistory();
    }
});
