// ABC Library Book Helper Functions

// Generate random rating for books with 0.0 rating
function generateDisplayRating(dbRating) {
    if (dbRating > 0) return dbRating;
    // Generate random rating between 3.5-5.0
    return Math.round((3.5 + (Math.random() * 1.5)) * 10) / 10;
}

// Create book image placeholder
function createBookPlaceholder(title, author, bookId) {
    const canvas = document.createElement('canvas');
    canvas.width = 200;
    canvas.height = 280;
    const ctx = canvas.getContext('2d');
    
    // Background gradient
    const gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, '#4A90E2');
    gradient.addColorStop(1, '#357ABD');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 200, 280);
    
    // Text styling
    ctx.fillStyle = 'white';
    ctx.textAlign = 'center';
    
    // ABC Library logo text
    ctx.font = 'bold 16px Arial';
    ctx.fillText('ABC Library', 100, 40);
    
    // Book title
    ctx.font = '12px Arial';
    const truncatedTitle = title.length > 20 ? title.substring(0, 20) + '...' : title;
    ctx.fillText(truncatedTitle, 100, 120);
    
    // Author
    ctx.font = '10px Arial';
    const truncatedAuthor = author.length > 15 ? author.substring(0, 15) + '...' : author;
    ctx.fillText('by ' + truncatedAuthor, 100, 140);
    
    // Book ID
    ctx.font = '8px Arial';
    ctx.fillText('Book ID: ' + bookId, 100, 200);
    
    return canvas.toDataURL();
}

// Handle book image loading errors
function handleBookImageError(img, title, author, bookId) {
    img.src = createBookPlaceholder(title, author, bookId);
    img.classList.add('placeholder-image');
}

// Initialize book image error handling
function initBookImageHandling() {
    document.querySelectorAll('.book-card img').forEach(img => {
        const bookCard = img.closest('.book-card');
        const title = bookCard.querySelector('.book-title')?.textContent || 'Unknown Book';
        const author = bookCard.querySelector('.book-author')?.textContent?.replace('by ', '') || 'Unknown Author';
        const bookId = img.closest('[data-book-id]')?.dataset.bookId || 'N/A';
        
        img.addEventListener('error', function() {
            handleBookImageError(this, title, author, bookId);
        });
        
        img.addEventListener('load', function() {
            this.classList.add('image-loaded');
        });
    });
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBookImageHandling);
} else {
    initBookImageHandling();
}

// Export functions for global use
window.BookHelper = {
    generateDisplayRating,
    createBookPlaceholder,
    handleBookImageError,
    initBookImageHandling
};