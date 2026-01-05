// Test Google Search function
async function searchGoogle(query) {
  console.log('[Google Search] Searching for:', query);
  
  try {
    const searchUrl = `https://www.google.com/search?q=${encodeURIComponent(query)}&hl=ar&gl=sa&num=10`;
    
    const response = await fetch(searchUrl, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'ar,en;q=0.9',
      }
    });

    console.log('Response status:', response.status);

    if (!response.ok) {
      console.log('[Google Search] Failed:', response.status);
      return { results: [], searchedAt: new Date().toISOString() };
    }

    const html = await response.text();
    console.log('HTML length:', html.length);
    
    // Check if blocked
    if (html.includes('unusual traffic') || html.includes('captcha')) {
      console.log('⚠️ Google blocked the request (captcha/unusual traffic)');
    }
    
    // Extract search results using regex patterns
    const results = [];
    
    // Extract URLs and titles from search results
    const urlPattern = /href="\/url\?q=([^&"]+)/g;
    const titlePattern = /<h3[^>]*>([^<]+)<\/h3>/g;
    
    let urlMatch;
    const urls = [];
    while ((urlMatch = urlPattern.exec(html)) !== null) {
      const url = decodeURIComponent(urlMatch[1]);
      if (!url.includes('google.com') && !url.includes('youtube.com/results')) {
        urls.push(url);
      }
    }
    
    let titleMatch;
    const titles = [];
    while ((titleMatch = titlePattern.exec(html)) !== null) {
      titles.push(titleMatch[1]);
    }
    
    console.log('URLs found:', urls.length);
    console.log('Titles found:', titles.length);
    
    // Combine URLs and titles
    for (let i = 0; i < Math.min(urls.length, 10); i++) {
      results.push({
        url: urls[i],
        title: titles[i] || 'نتيجة بحث',
        position: i + 1
      });
    }
    
    console.log('\n=== Results ===');
    results.forEach((r, i) => {
      console.log(`${i + 1}. ${r.title}`);
      console.log(`   ${r.url}\n`);
    });
    
    return { results, searchedAt: new Date().toISOString() };
    
  } catch (error) {
    console.error('[Google Search] Error:', error.message);
    return { results: [], searchedAt: new Date().toISOString() };
  }
}

// Test
searchGoogle('القثامي للاستقدام');
