// Enhanced ABC Library Chatbot with Direct Book Search
class ABCLibraryChatbot {
    constructor() {
        this.isOpen = false;
        this.messages = [];
        this.searchKeywords = ['does your library have', 'do you have', 'library have', 'book available', 'search for', 'find'];
        this.hasShownGreeting = false;
        this.lastSearchTime = 0;
        this.searchCooldown = 1000; // 1 second cooldown between searches
        this.init();
    }
    
    init() {
        this.bindEvents();
        // Show auto greeting after page load
        setTimeout(() => {
            this.showAutoGreeting();
        }, 3000); // Show after 3 seconds
    }
    
    showAutoGreeting() {
        if (this.hasShownGreeting) return;
        
        this.hasShownGreeting = true;
        const chatWindow = document.getElementById('chatbot-window');
        const chatButton = document.getElementById('chatbot-button');
        
        // Add greeting animation class
        chatButton.classList.add('greeting-bounce');
        
        // Open chat window
        chatWindow.classList.add('show');
        chatWindow.classList.add('auto-greeting');
        this.isOpen = true;
        
        // Add the greeting message with animation
        setTimeout(() => {
            this.addMessage("Hi there! 👋 Welcome to ABC Library!", 'bot');
            this.addMessage("I'm your AI assistant. You can search for books by simply typing the book name, or ask me anything about our library! 📚✨", 'bot');
        }, 500);
        
        // Auto-close after 6 seconds if user doesn't interact
        setTimeout(() => {
            if (chatWindow.classList.contains('auto-greeting')) {
                this.closeAutoGreeting();
            }
        }, 6000);
    }
    
    closeAutoGreeting() {
        const chatWindow = document.getElementById('chatbot-window');
        const chatButton = document.getElementById('chatbot-button');
        const badge = document.getElementById('notificationBadge');
        
        chatWindow.classList.remove('show');
        chatWindow.classList.remove('auto-greeting');
        chatButton.classList.remove('greeting-bounce');
        this.isOpen = false;
        
        // Add a subtle notification pulse and badge
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
        }, 4000);
    }
    
    bindEvents() {
        // Send message on Enter key
        const inputField = document.getElementById('chatbotInputField');
        if (inputField) {
            inputField.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.sendMessage();
                    this.removeAutoGreeting();
                }
            });
            
            // Remove auto greeting when user starts typing
            inputField.addEventListener('input', () => {
                this.removeAutoGreeting();
            });
        }
        
        // Send message on button click
        const sendBtn = document.getElementById('chatbotSendBtn');
        if (sendBtn) {
            sendBtn.addEventListener('click', () => {
                this.sendMessage();
                this.removeAutoGreeting();
            });
        }
    }
    
    removeAutoGreeting() {
        const chatWindow = document.getElementById('chatbot-window');
        const badge = document.getElementById('notificationBadge');
        
        if (chatWindow.classList.contains('auto-greeting')) {
            chatWindow.classList.remove('auto-greeting');
            if (badge) {
                badge.classList.remove('show');
            }
        }
    }
    
    sendMessage() {
        const inputField = document.getElementById('chatbotInputField');
        const message = inputField.value.trim();
        
        if (!message) return;
        
        // Check for rate limiting
        const now = Date.now();
        if (now - this.lastSearchTime < this.searchCooldown) {
            this.addMessage("Please wait a moment before sending another message! 😊", 'bot');
            return;
        }
        
        // Add user message
        this.addMessage(message, 'user');
        
        // Clear input
        inputField.value = '';
        
        // Process message and get bot response
        setTimeout(() => {
            this.processMessage(message);
        }, 800);
    }
    
    addMessage(content, sender) {
        const messagesContainer = document.getElementById('chatbotMessages');
        if (!messagesContainer) return;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}-message`;
        messageDiv.innerHTML = `
            <div class="message-content">
                <p>${content}</p>
                <small class="message-time">${this.getCurrentTime()}</small>
            </div>
        `;
        
        // Add animation class
        messageDiv.classList.add('message-enter');
        
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Remove animation class after animation completes
        setTimeout(() => {
            messageDiv.classList.remove('message-enter');
        }, 300);
    }
    
    processMessage(message) {
        const lowerMessage = message.toLowerCase().trim();
        
        // Check if user is asking about book availability (with keywords)
        const isKeywordSearch = this.searchKeywords.some(keyword => 
            lowerMessage.includes(keyword.toLowerCase())
        );
        
        // Check if message looks like a direct book search
        const isDirectBookSearch = this.isLikelyBookSearch(lowerMessage);
        
        if (isKeywordSearch) {
            this.handleBookSearch(message);
        } else if (isDirectBookSearch && !this.isGeneralGreeting(lowerMessage)) {
            // Treat as direct book search
            this.handleDirectBookSearch(message);
        } else {
            this.handleGeneralMessage(lowerMessage);
        }
    }
    
    isLikelyBookSearch(message) {
        // Don't treat very short messages or common phrases as book searches
        if (message.length < 3) return false;
        
        const generalWords = [
            'hello', 'hi', 'hey', 'thanks', 'thank you', 'bye', 'goodbye',
            'help', 'what', 'how', 'where', 'when', 'why', 'who',
            'hours', 'time', 'open', 'close', 'location', 'address',
            'contact', 'phone', 'email', 'membership', 'join'
        ];
        
        // If message contains only general words, don't treat as book search
        const words = message.toLowerCase().split(' ');
        const hasOnlyGeneralWords = words.every(word => 
            generalWords.some(generalWord => word.includes(generalWord) || generalWord.includes(word))
        );
        
        if (hasOnlyGeneralWords) return false;
        
        // If message contains book-like terms or is long enough, treat as potential book search
        const bookIndicators = ['book', 'novel', 'story', 'author', 'writer', 'read'];
        const hasBookIndicators = bookIndicators.some(indicator => 
            message.toLowerCase().includes(indicator)
        );
        
        // Treat as book search if:
        // 1. Has book indicators, or
        // 2. Is longer than 2 words and doesn't contain question words at the start, or
        // 3. Contains numbers (could be ISBN or year), or
        // 4. Contains capital letters (might be proper nouns like book titles)
        return hasBookIndicators || 
               (words.length > 2 && !words[0].match(/^(what|how|where|when|why|who)$/)) ||
               message.match(/\d/) ||
               message.match(/[A-Z]/);
    }
    
    isGeneralGreeting(message) {
        const greetings = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening'];
        return greetings.some(greeting => message.includes(greeting));
    }
    
    async handleDirectBookSearch(message) {
        // Clean the message for direct book search
        const bookName = message.trim();
        
        this.addMessage(`🔍 Searching for "${bookName}" in our library...`, 'bot');
        
        // Show typing indicator
        this.showTyping();
        
        try {
            this.lastSearchTime = Date.now();
            
            // Search for the book
            const response = await fetch(`api/chatbot_search.php?q=${encodeURIComponent(bookName)}`);
            const data = await response.json();
            
            this.hideTyping();
            
            if (data.success && data.books && data.books.length > 0) {
                const book = data.books[0]; // Get first result
                const bookInfo = `📚 <strong>Great news! We have "${book.title}"!</strong><br><br>
                    📖 <strong>Author:</strong> ${book.author}<br>
                    📅 <strong>Published:</strong> ${book.publication_year || 'N/A'}<br>
                    📍 <strong>Location:</strong> ${book.rack_number || 'Available'}<br>
                    ${book.dewey_decimal_number ? `🏷️ <strong>Classification:</strong> ${book.dewey_decimal_number}<br>` : ''}
                    ✅ <strong>Status:</strong> ${book.available_copies > 0 ? 
                        `Available now (${book.available_copies} copies)` : 
                        '📝 Currently borrowed'}<br><br>
                    ${book.description ? 
                        `📝 <strong>Description:</strong> ${book.description.substring(0, 120)}${book.description.length > 120 ? '...' : ''}<br><br>` : 
                        ''
                    }
                    🎉 <strong>Ready to read?</strong> Visit our library to borrow this amazing book!`;
                
                this.addMessage(bookInfo, 'bot');
                
                // If there are more results, mention them
                if (data.books.length > 1) {
                    this.addMessage(`💡 We found ${data.books.length} books matching your search. The one above is our top recommendation!`, 'bot');
                }
            } else {
                this.addMessage(`📚 I couldn't find "${bookName}" in our current collection.<br><br>But here's the good news! 🌟<br><br>📖 We're constantly adding new books to our collection<br>📝 You can request this book and we'll try to get it<br>🔍 Try searching with different keywords or author name<br>💡 I can also suggest similar books you might enjoy!<br><br><strong>Need help with your search? Just ask!</strong> 🚀`, 'bot');
            }
        } catch (error) {
            console.error('Search error:', error);
            this.hideTyping();
            this.addMessage("🔧 I'm having trouble accessing the library database right now. Please try again in a moment! 😅", 'bot');
        }
    }
    
    async handleBookSearch(message) {
        // Extract book name from message with keywords
        const bookName = this.extractBookName(message);
        
        if (!bookName) {
            this.addMessage("Please specify the book name you're looking for! 📚<br><br>You can simply type the book title, like:<br>• 'Harry Potter'<br>• 'The Great Gatsby'<br>• 'Pride and Prejudice'", 'bot');
            return;
        }
        
        // Use the same search logic as direct search
        await this.handleDirectBookSearch(bookName);
    }
    
    handleGeneralMessage(message) {
        let response = '';
        
        if (message.includes('hello') || message.includes('hi') || message.includes('hey')) {
            response = "Hello there! 👋 Welcome to ABC Library! I'm your AI assistant.<br><br>💡 <strong>Quick tip:</strong> You can search for books by simply typing the book name!<br><br>Try typing:<br>• 'Sri Lanka'<br>• 'Harry Potter'<br>• 'Introduction to Algorithm'<br><br>Or ask me about library hours, location, or services! 📖✨";
        } else if (message.includes('help') || message.includes('how')) {
            response = "I'm here to help! 🤖 Here's what I can do:<br><br>📚 <strong>Book Search:</strong><br>• Simply type any book name (e.g., 'Sri Lanka')<br>• Ask 'Do you have [book name]?'<br><br>ℹ️ <strong>Library Info:</strong><br>• Library hours and location<br>• Contact information<br>• Membership details<br><br>🎯 <strong>Quick Examples:</strong><br>• 'Sri Lanka' → Direct book search<br>• 'What are your hours?' → Library information<br>• 'How do I join?' → Membership info<br><br>What would you like to know? ✨";
        } else if (message.includes('thank')) {
            response = "You're absolutely welcome! 😊 I love helping book lovers find their next great read!<br><br>💡 Remember, you can always search for books by just typing the title. Is there anything else I can help you with? 📚✨";
        } else if (message.includes('bye') || message.includes('goodbye')) {
            response = "Goodbye for now! 👋 Remember, amazing books are waiting for you at ABC Library.<br><br>📚 Come back anytime - I'm always here to help you discover your next favorite book! ✨";
        } else if (message.includes('hours') || message.includes('open') || message.includes('time')) {
            response = "🕒 <strong>ABC Library Hours:</strong><br><br>📅 Monday - Friday: 8:00 AM - 10:00 PM<br>📅 Saturday - Sunday: 9:00 AM - 8:00 PM<br><br>We're open every day to fuel your love for reading! 📚<br><br>Need directions or want to contact us? Just ask!";
        } else if (message.includes('location') || message.includes('address') || message.includes('where')) {
            response = "📍 <strong>Find ABC Library Here:</strong><br><br>🏢 ABC Library<br>📮 123 Library Street<br>🌆 Knowledge City, KC 12345<br>📞 +1 (555) 123-4567<br><br>We're in the heart of the city, easily accessible by public transport! 🚌<br><br>🗺️ Look for the building with our logo - you can't miss it!";
        } else if (message.includes('contact') || message.includes('phone') || message.includes('email')) {
            response = "📞 <strong>Contact ABC Library:</strong><br><br>☎️ Phone: +1 (555) 123-4567<br>📧 Email: info@abclibrary.com<br>💬 Live Chat: Right here with me!<br>🌐 Website: www.abclibrary.com<br><br>We're always happy to help! 😊<br><br>📚 Or just keep chatting with me for instant book searches!";
        } else if (message.includes('membership') || message.includes('join') || message.includes('register')) {
            response = "📝 <strong>Join ABC Library Today!</strong><br><br>✅ Free membership registration<br>📚 Borrow up to 5 books at once<br>💻 Access to digital resources<br>📖 Reserve books online through our system<br>🎉 Join book clubs and events<br>🤖 24/7 AI assistance (that's me!)<br><br>🚀 Visit us or click 'JOIN NOW' on our website to get started!<br><br>Questions about membership? Just ask!";
        } else if (message.includes('what') && (message.includes('book') || message.includes('have'))) {
            response = "📚 <strong>ABC Library has thousands of books!</strong><br><br>🔍 <strong>Search made easy:</strong><br>Just type any book name and I'll find it for you!<br><br>Examples:<br>• 'Sri Lanka' - for books about or titled Sri Lanka<br>• 'Harry Potter' - for the famous series<br>• 'Programming' - for programming books<br><br>📖 We have books in categories like:<br>• Fiction & Literature<br>• Science & Technology<br>• History & Geography<br>• Art & Culture<br>• Education & Reference<br><br>What book are you looking for? 🎯";
        } else {
            response = "I'm here to help you explore ABC Library! 🤖📚<br><br>🎯 <strong>Quick Actions:</strong><br>• Type any book name for instant search<br>• Ask 'What are your hours?'<br>• Ask 'How do I join the library?'<br>• Ask 'Where are you located?'<br><br>💡 <strong>Book Search Examples:</strong><br>• 'Sri Lanka' → Search for Sri Lanka books<br>• 'Chess' → Find chess-related books<br>• 'Nature' → Discover nature books<br><br>What can I help you find today? ✨";
        }
        
        this.addMessage(response, 'bot');
    }
    
    extractBookName(message) {
        const lowerMessage = message.toLowerCase();
        let bookName = '';
        
        // Find the keyword and extract what comes after it
        for (const keyword of this.searchKeywords) {
            const index = lowerMessage.indexOf(keyword.toLowerCase());
            if (index !== -1) {
                bookName = message.substring(index + keyword.length).trim();
                break;
            }
        }
        
        // Clean up the book name
        bookName = bookName.replace(/[?!.]/g, '').trim();
        
        return bookName;
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
}

// Chatbot Control Functions
function toggleChatbot() {
    const window = document.getElementById('chatbot-window');
    const button = document.getElementById('chatbot-button');
    const badge = document.getElementById('notificationBadge');
    
    if (window.classList.contains('show')) {
        window.classList.remove('show');
        window.classList.remove('auto-greeting');
        if (window.chatbot) {
            window.chatbot.isOpen = false;
        }
    } else {
        window.classList.add('show');
        if (badge) {
            badge.classList.remove('show');
        }
        if (window.chatbot) {
            window.chatbot.isOpen = true;
        }
        // Focus on input when opening
        setTimeout(() => {
            const input = document.getElementById('chatbotInputField');
            if (input) input.focus();
        }, 300);
    }
}

function sendMessage() {
    if (window.chatbot) {
        window.chatbot.sendMessage();
    }
}

function quickSearch(query) {
    const input = document.getElementById('chatbotInputField');
    if (input) {
        input.value = query; // Direct search without "Does your library have"
        if (window.chatbot) {
            window.chatbot.sendMessage();
        }
    }
    
    // Open chatbot if closed
    const chatWindow = document.getElementById('chatbot-window');
    if (!chatWindow.classList.contains('show')) {
        toggleChatbot();
    }
}

// Initialize chatbot when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize chatbot
    window.chatbot = new ABCLibraryChatbot();
    
    // Add some custom styles for better UX
    const style = document.createElement('style');
    style.textContent = `
        .chatbot-window .message-content p {
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }
        
        .chatbot-window .message-content strong {
            color: var(--primary-color, #4A90E2);
        }
        
        .typing-indicator {
            display: flex;
            gap: 4px;
            align-items: center;
            padding: 8px 12px;
        }
        
        .typing-indicator span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary-color, #4A90E2);
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-indicator span:nth-child(1) { animation-delay: -0.32s; }
        .typing-indicator span:nth-child(2) { animation-delay: -0.16s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1.2); opacity: 1; }
        }
        
        .message-enter {
            animation: messageSlideIn 0.3s ease-out;
        }
        
        @keyframes messageSlideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .greeting-bounce {
            animation: greetingBounce 2s infinite;
        }
        
        @keyframes greetingBounce {
            0%, 20%, 53%, 80%, 100% { transform: translate3d(0,0,0); }
            40%, 43% { transform: translate3d(0, -8px, 0); }
            70% { transform: translate3d(0, -4px, 0); }
            90% { transform: translate3d(0, -2px, 0); }
        }
        
        .has-notification {
            animation: notificationPulse 2s infinite;
        }
        
        @keyframes notificationPulse {
            0% { box-shadow: 0 0 0 0 rgba(74, 144, 226, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(74, 144, 226, 0); }
            100% { box-shadow: 0 0 0 0 rgba(74, 144, 226, 0); }
        }
    `;
    document.head.appendChild(style);
});

