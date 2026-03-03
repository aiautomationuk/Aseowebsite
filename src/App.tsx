/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useEffect } from 'react';
import { GoogleGenAI } from "@google/genai";
import { 
  ArrowRight, 
  CheckCircle2, 
  Zap, 
  BarChart3, 
  Search, 
  Globe, 
  Cpu, 
  Menu, 
  X, 
  ChevronDown,
  Star,
  ShieldCheck,
  TrendingUp,
  Clock,
  Layers
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import { 
  AreaChart,
  Area,
  XAxis, 
  YAxis, 
  CartesianGrid, 
  Tooltip, 
  ResponsiveContainer 
} from 'recharts';

// Initialize Gemini
const genAI = new GoogleGenAI({ apiKey: process.env.GEMINI_API_KEY || '' });

// --- Mock Data for GSC Graph ---
const gscData = [
  { date: 'Jan 1', clicks: 120, impressions: 1200 },
  { date: 'Jan 5', clicks: 180, impressions: 1800 },
  { date: 'Jan 10', clicks: 250, impressions: 2400 },
  { date: 'Jan 15', clicks: 420, impressions: 3800 },
  { date: 'Jan 20', clicks: 580, impressions: 5200 },
  { date: 'Jan 25', clicks: 890, impressions: 7800 },
  { date: 'Feb 1', clicks: 1200, impressions: 11000 },
  { date: 'Feb 5', clicks: 1500, impressions: 14500 },
  { date: 'Feb 10', clicks: 2100, impressions: 19800 },
  { date: 'Feb 15', clicks: 2800, impressions: 26500 },
  { date: 'Feb 20', clicks: 3400, impressions: 32000 },
  { date: 'Feb 25', clicks: 4200, impressions: 39500 },
  { date: 'Mar 1', clicks: 5100, impressions: 48000 },
];

const GSCPreview = () => {
  return (
    <div className="rounded-2xl border border-slate-200 shadow-2xl overflow-hidden bg-white">
      <div className="bg-slate-50 border-b border-slate-200 px-6 py-4 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex gap-1.5">
            <div className="w-3 h-3 rounded-full bg-red-400" />
            <div className="w-3 h-3 rounded-full bg-amber-400" />
            <div className="w-3 h-3 rounded-full bg-emerald-400" />
          </div>
          <div className="h-4 w-px bg-slate-200 mx-2" />
          <div className="flex items-center gap-2 text-slate-500 font-medium text-sm">
            <Search size={16} />
            <span>Search Console</span>
          </div>
        </div>
        <div className="bg-white rounded-md px-3 py-1 text-[10px] text-slate-400 font-mono border border-slate-200 shadow-sm">
          app.auto-seo.co.uk/performance
        </div>
      </div>
      
      <div className="p-8">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
          <div className="p-4 rounded-xl bg-blue-50 border border-blue-100">
            <p className="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">Total Clicks</p>
            <p className="text-2xl font-extrabold text-blue-900">5.1K</p>
            <div className="flex items-center gap-1 text-emerald-600 text-xs font-bold mt-1">
              <TrendingUp size={12} /> +142%
            </div>
          </div>
          <div className="p-4 rounded-xl bg-purple-50 border border-purple-100">
            <p className="text-xs font-bold text-purple-600 uppercase tracking-wider mb-1">Total Impressions</p>
            <p className="text-2xl font-extrabold text-purple-900">48K</p>
            <div className="flex items-center gap-1 text-emerald-600 text-xs font-bold mt-1">
              <TrendingUp size={12} /> +86%
            </div>
          </div>
          <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-100">
            <p className="text-xs font-bold text-emerald-600 uppercase tracking-wider mb-1">Avg. CTR</p>
            <p className="text-2xl font-extrabold text-emerald-900">10.6%</p>
            <div className="flex items-center gap-1 text-emerald-600 text-xs font-bold mt-1">
              <TrendingUp size={12} /> +12%
            </div>
          </div>
          <div className="p-4 rounded-xl bg-amber-50 border border-amber-100">
            <p className="text-xs font-bold text-amber-600 uppercase tracking-wider mb-1">Avg. Position</p>
            <p className="text-2xl font-extrabold text-amber-900">2.4</p>
            <div className="flex items-center gap-1 text-emerald-600 text-xs font-bold mt-1">
              <TrendingUp size={12} /> +4.2
            </div>
          </div>
        </div>

        <div className="h-[300px] w-full">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={gscData}>
              <defs>
                <linearGradient id="colorClicks" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#3b82f6" stopOpacity={0.1}/>
                  <stop offset="95%" stopColor="#3b82f6" stopOpacity={0}/>
                </linearGradient>
                <linearGradient id="colorImpressions" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#a855f7" stopOpacity={0.1}/>
                  <stop offset="95%" stopColor="#a855f7" stopOpacity={0}/>
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
              <XAxis 
                dataKey="date" 
                axisLine={false} 
                tickLine={false} 
                tick={{ fill: '#94a3b8', fontSize: 12 }}
                dy={10}
              />
              <YAxis hide />
              <Tooltip 
                contentStyle={{ 
                  borderRadius: '12px', 
                  border: 'none', 
                  boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1)',
                  padding: '12px'
                }}
              />
              <Area 
                type="monotone" 
                dataKey="clicks" 
                stroke="#3b82f6" 
                strokeWidth={3}
                fillOpacity={1} 
                fill="url(#colorClicks)" 
                animationDuration={2000}
              />
              <Area 
                type="monotone" 
                dataKey="impressions" 
                stroke="#a855f7" 
                strokeWidth={3}
                fillOpacity={1} 
                fill="url(#colorImpressions)" 
                animationDuration={2500}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  );
};

// --- Components ---

const Navbar = () => {
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  useEffect(() => {
    const handleScroll = () => setIsScrolled(window.scrollY > 20);
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  const navLinks = [
    { name: 'Features', href: '#features' },
    { name: 'How it Works', href: '#how-it-works' },
    { name: 'Pricing', href: '#pricing' },
    { name: 'FAQ', href: '#faq' },
  ];

  return (
    <nav className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${isScrolled ? 'glass py-3 shadow-sm' : 'bg-transparent py-5'}`}>
      <div className="max-w-7xl mx-auto px-6 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-10 h-10 bg-primary rounded-xl flex items-center justify-center text-white">
            <Zap size={24} fill="currentColor" />
          </div>
          <span className="text-xl font-extrabold tracking-tight text-slate-900">Auto-Seo<span className="text-primary">.co.uk</span></span>
        </div>

        {/* Desktop Nav */}
        <div className="hidden md:flex items-center gap-8">
          {navLinks.map((link) => (
            <a key={link.name} href={link.href} className="text-sm font-medium text-slate-600 hover:text-primary transition-colors">
              {link.name}
            </a>
          ))}
        </div>

        <div className="hidden md:flex items-center gap-4">
          <button className="text-sm font-semibold text-slate-600 hover:text-primary transition-colors">Login</button>
          <button className="btn-primary py-2 px-5 text-sm">Get Started</button>
        </div>

        {/* Mobile Menu Toggle */}
        <button className="md:hidden text-slate-900" onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}>
          {isMobileMenuOpen ? <X size={24} /> : <Menu size={24} />}
        </button>
      </div>

      {/* Mobile Menu */}
      <AnimatePresence>
        {isMobileMenuOpen && (
          <motion.div 
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            className="md:hidden bg-white border-t border-slate-100 overflow-hidden"
          >
            <div className="flex flex-col p-6 gap-4">
              {navLinks.map((link) => (
                <a 
                  key={link.name} 
                  href={link.href} 
                  className="text-lg font-medium text-slate-600"
                  onClick={() => setIsMobileMenuOpen(false)}
                >
                  {link.name}
                </a>
              ))}
              <hr className="border-slate-100" />
              <button className="text-lg font-semibold text-slate-600 text-left">Login</button>
              <button className="btn-primary w-full">Get Started</button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </nav>
  );
};

const Hero = () => {
  const [url, setUrl] = useState('');
  const [isScanning, setIsScanning] = useState(false);
  const [scanResult, setScanResult] = useState<{ summary: string; keywords: string[] } | null>(null);
  const [error, setError] = useState('');
  const [progress, setProgress] = useState(0);
  const [progressLabel, setProgressLabel] = useState('Preparing scan');

  const handleScan = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!url) return;

    setIsScanning(true);
    setError('');
    setScanResult(null);
    setProgress(10);
    setProgressLabel('Connecting to the website');

    let normalizedUrl = url.trim();
    if (!/^https?:\/\//i.test(normalizedUrl)) {
      normalizedUrl = `https://${normalizedUrl}`;
    }

    const timer = window.setInterval(() => {
      setProgress((prev) => Math.min(prev + Math.random() * 12, 90));
    }, 700);

    try {
      // Fetch website text via Jina (no CORS issues)
      setProgressLabel('Reading website content');
      let websiteText: string | null = null;
      try {
        const parsed = new URL(normalizedUrl);
        const jinaUrl = `https://r.jina.ai/${parsed.href}`;
        const jinaRes = await fetch(jinaUrl, { cache: 'no-store' });
        if (jinaRes.ok) {
          const text = await jinaRes.text();
          const cleaned = text.replace(/\s+/g, ' ').trim();
          if (cleaned.length >= 200) websiteText = cleaned.slice(0, 6000);
        }
      } catch { /* fall through */ }

      if (!websiteText) throw new Error('Could not read that website. Please check the URL and try again.');

      setProgressLabel('Analysing with AI');

      const apiKey = (import.meta as Record<string, unknown> & { env: Record<string, string> }).env.VITE_OPENAI_API_KEY ?? '';
      if (!apiKey) throw new Error('OpenAI API key not configured.');

      const prompt = `You are an SEO expert. Analyse this website content and return ONLY valid JSON (no markdown) with exactly these keys:
{
  "summary": "one sentence describing what the business does",
  "keywords": ["keyword1", "keyword2", "keyword3", "keyword4", "keyword5"],
  "services": ["service1", "service2", "service3"],
  "competitors": [{"name": "Competitor Name", "url": "https://example.com"}]
}

Website URL: ${normalizedUrl}
Website content:
"""${websiteText}"""`;

      const response = await fetch('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${apiKey}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          model: 'gpt-4o-mini',
          messages: [{ role: 'user', content: prompt }],
          temperature: 0.2,
        }),
      });

      if (!response.ok) throw new Error('AI scan failed. Please try again.');

      const data = await response.json();
      const content: string = data?.choices?.[0]?.message?.content ?? '';
      const trimmed = content.trim().replace(/^```json\n?/, '').replace(/\n?```$/, '');
      const result = JSON.parse(trimmed);
      setScanResult(result);
    } catch (err) {
      console.error(err);
      setError(err instanceof Error ? err.message : 'Failed to scan website. Please try again.');
    } finally {
      window.clearInterval(timer);
      setProgress(100);
      setProgressLabel('Scan complete');
      setIsScanning(false);
    }
  };

  return (
    <section className="relative pt-32 pb-20 md:pt-48 md:pb-32 overflow-hidden">
      {/* Background blobs */}
      <div className="absolute top-0 right-0 -translate-y-1/2 translate-x-1/4 w-[600px] h-[600px] bg-primary/5 rounded-full blur-3xl -z-10" />
      <div className="absolute bottom-0 left-0 translate-y-1/2 -translate-x-1/4 w-[600px] h-[600px] bg-blue-500/5 rounded-full blur-3xl -z-10" />

      <div className="max-w-7xl mx-auto px-6 text-center">
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.6 }}
        >
          {/* Social Proof Badge */}
          <div className="flex flex-col items-center mb-8">
            <div className="flex items-center gap-4">
              <div className="flex -space-x-3">
                {[1, 2, 3, 4, 5].map((i) => (
                  <img
                    key={i}
                    className="w-10 h-10 rounded-full border-2 border-white object-cover"
                    src={`https://picsum.photos/seed/user${i}/100/100`}
                    alt={`User ${i}`}
                    referrerPolicy="no-referrer"
                  />
                ))}
              </div>
              <div className="flex flex-col items-start">
                <div className="flex gap-0.5 text-amber-400">
                  {[1, 2, 3, 4, 5].map((i) => (
                    <Star key={i} size={16} fill="currentColor" />
                  ))}
                </div>
                <p className="text-sm font-bold text-slate-700">100k+ Articles Created</p>
              </div>
            </div>
          </div>

          <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-primary/10 text-primary text-xs font-bold uppercase tracking-wider mb-6">
            <Star size={14} fill="currentColor" />
            AI-Powered SEO Automations
          </span>
          <h1 className="text-5xl md:text-7xl font-extrabold text-slate-900 tracking-tight leading-[1.1] mb-8">
            Grow Organic Traffic <br />
            <span className="text-primary">With Auto-Seo.co.uk</span>
          </h1>
          <p className="text-lg md:text-xl text-slate-600 max-w-2xl mx-auto mb-10 leading-relaxed">
            Get traffic and outrank competitors with AI-optimised content, backlink building, and technical SEO while you sleep.
          </p>

          {/* URL Scanner Box */}
          <div className="max-w-2xl mx-auto mb-10">
            <div className="rounded-2xl border border-slate-200 bg-white/90 p-5 shadow-sm text-left">
              <form onSubmit={handleScan} className="space-y-3">
                <div className="flex flex-col gap-3 sm:flex-row">
                  <div className="flex w-full items-center rounded-lg border-2 border-rose-500 bg-white px-3 py-3 text-sm text-slate-500 focus-within:ring-2 focus-within:ring-rose-200 shadow-[0_0_0_1px_rgba(244,63,94,0.8)]">
                    <span className="mr-1 select-none text-slate-400">https://</span>
                    <input
                      type="text"
                      className="w-full bg-transparent text-sm text-slate-900 outline-none"
                      placeholder="yourwebsite.com"
                      value={url}
                      onChange={(e) => setUrl(e.target.value)}
                      disabled={isScanning}
                      required
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={isScanning}
                    className="btn-primary py-3 px-6 text-sm whitespace-nowrap disabled:opacity-70 disabled:cursor-not-allowed"
                  >
                    {isScanning ? 'Scanning...' : 'Start scan →'}
                  </button>
                </div>
                {error ? (
                  <p className="text-xs text-rose-600">{error}</p>
                ) : (
                  <p className="text-xs text-slate-500">
                    We will handle the technical setup. Just give us your URL.
                  </p>
                )}
              </form>

              {isScanning && (
                <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-full border border-slate-200 bg-white flex items-center justify-center">
                      <span className="inline-flex h-5 w-5 animate-spin rounded-full border-2 border-slate-300 border-t-slate-900" />
                    </div>
                    <div>
                      <div className="text-sm font-semibold text-slate-900">Scanning website</div>
                      <div className="text-xs text-slate-500">{progressLabel}</div>
                    </div>
                  </div>
                  <div className="mt-4 h-2 w-full rounded-full bg-slate-100">
                    <div
                      className="h-2 rounded-full bg-slate-900 transition-all duration-500"
                      style={{ width: `${Math.round(progress)}%` }}
                    />
                  </div>
                  <div className="mt-2 text-xs text-slate-500">{Math.round(progress)}% complete</div>
                </div>
              )}
            </div>
          </div>

          {/* Scan Results */}
          <AnimatePresence>
            {scanResult && (
              <motion.div
                initial={{ opacity: 0, scale: 0.95, y: 20 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95, y: 20 }}
                className="max-w-3xl mx-auto mt-8 p-8 bg-white rounded-3xl border border-primary/20 shadow-2xl text-left relative overflow-hidden"
              >
                <div className="absolute top-0 right-0 p-4 opacity-10">
                  <Zap size={80} className="text-primary" />
                </div>
                <h3 className="text-2xl font-bold text-slate-900 mb-4 flex items-center gap-2">
                  <CheckCircle2 className="text-emerald-500" />
                  AI Analysis Complete
                </h3>
                <div className="space-y-6 relative z-10">
                  <div>
                    <h4 className="text-xs font-bold uppercase tracking-widest text-primary mb-2">Business Summary</h4>
                    <p className="text-slate-700 leading-relaxed">{scanResult.summary}</p>
                  </div>
                  <div>
                    <h4 className="text-xs font-bold uppercase tracking-widest text-primary mb-3">Recommended Keywords</h4>
                    <div className="flex flex-wrap gap-2">
                      {scanResult.keywords.map((kw, i) => (
                        <span key={i} className="px-4 py-2 bg-primary/5 text-primary rounded-full text-sm font-semibold border border-primary/10">
                          {kw}
                        </span>
                      ))}
                    </div>
                  </div>
                  {(scanResult as { services?: string[] }).services?.length ? (
                    <div>
                      <h4 className="text-xs font-bold uppercase tracking-widest text-primary mb-3">Top Services Detected</h4>
                      <div className="flex flex-wrap gap-2">
                        {(scanResult as { services?: string[] }).services!.map((s, i) => (
                          <span key={i} className="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-full text-sm font-semibold border border-emerald-100">
                            {s}
                          </span>
                        ))}
                      </div>
                    </div>
                  ) : null}
                  {(scanResult as { competitors?: { name: string; url: string }[] }).competitors?.length ? (
                    <div>
                      <h4 className="text-xs font-bold uppercase tracking-widest text-primary mb-3">Main Competitors</h4>
                      <div className="flex flex-wrap gap-2">
                        {(scanResult as { competitors?: { name: string; url: string }[] }).competitors!.map((c, i) => (
                          <a key={i} href={c.url} target="_blank" rel="noopener noreferrer"
                            className="px-4 py-2 bg-slate-50 text-slate-700 rounded-full text-sm font-semibold border border-slate-200 hover:border-primary/30 hover:text-primary transition-colors">
                            {c.name}
                          </a>
                        ))}
                      </div>
                    </div>
                  ) : null}
                  <div className="pt-4 border-t border-slate-100 flex items-center justify-between">
                    <p className="text-sm text-slate-500 italic">Ready to rank for these? Start your trial today.</p>
                    <button className="text-primary font-bold text-sm hover:underline">Download Full Report</button>
                  </div>
                </div>
              </motion.div>
            )}
          </AnimatePresence>

          <div className="flex flex-col sm:flex-row items-center justify-center gap-4 mt-10">
            <motion.button 
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
              className="btn-primary w-full sm:w-auto px-8 py-4 text-lg flex items-center justify-center gap-2"
            >
              Start Free Trial <ArrowRight size={20} />
            </motion.button>
            <motion.button 
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
              className="btn-secondary w-full sm:w-auto px-8 py-4 text-lg"
            >
              Book a Demo
            </motion.button>
          </div>

          <div className="mt-8 flex justify-center">
            <button className="flex items-center gap-3 px-8 py-4 rounded-full border-[3px] border-primary/40 bg-white hover:bg-slate-50 transition-all active:scale-95 group">
              <svg className="w-6 h-6" viewBox="0 0 24 24">
                <path
                  fill="#4285F4"
                  d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                />
                <path
                  fill="#34A853"
                  d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                />
                <path
                  fill="#FBBC05"
                  d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"
                />
                <path
                  fill="#EA4335"
                  d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                />
              </svg>
              <span className="text-xl font-bold text-slate-900">Join with Google</span>
            </button>
          </div>
          
          <div className="mt-12 flex items-center justify-center gap-8 opacity-50 grayscale">
            <span className="font-bold text-xl tracking-tighter">TECHCRUNCH</span>
            <span className="font-bold text-xl tracking-tighter">FORBES</span>
            <span className="font-bold text-xl tracking-tighter">WIRED</span>
            <span className="font-bold text-xl tracking-tighter">VERGE</span>
          </div>
        </motion.div>

        <motion.div 
          initial={{ opacity: 0, y: 40 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.8, delay: 0.2 }}
          className="mt-20 relative max-w-5xl mx-auto"
        >
          <GSCPreview />
          
          {/* Floating elements */}
          <div className="absolute -bottom-10 -left-10 hidden lg:block">
            <motion.div 
              animate={{ y: [0, -10, 0] }}
              transition={{ duration: 4, repeat: Infinity, ease: "easeInOut" }}
              className="bg-white p-4 rounded-xl shadow-xl border border-slate-100 flex items-center gap-4"
            >
              <div className="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-lg flex items-center justify-center">
                <TrendingUp size={24} />
              </div>
              <div>
                <p className="text-xs text-slate-500 font-medium">Organic Traffic</p>
                <p className="text-xl font-bold text-slate-900">+142%</p>
              </div>
            </motion.div>
          </div>
          
          <div className="absolute -top-10 -right-10 hidden lg:block">
            <motion.div 
              animate={{ y: [0, 10, 0] }}
              transition={{ duration: 5, repeat: Infinity, ease: "easeInOut" }}
              className="bg-white p-4 rounded-xl shadow-xl border border-slate-100 flex items-center gap-4"
            >
              <div className="w-12 h-12 bg-primary/10 text-primary rounded-lg flex items-center justify-center">
                <Search size={24} />
              </div>
              <div>
                <p className="text-xs text-slate-500 font-medium">Keywords Ranked</p>
                <p className="text-xl font-bold text-slate-900">2,480</p>
              </div>
            </motion.div>
          </div>
        </motion.div>
      </div>
    </section>
  );
};

const Features = () => {
  const features = [
    {
      icon: <Cpu className="text-primary" size={32} />,
      title: "AI Content Engine",
      description: "Generate high-quality, SEO-optimised articles that rank for competitive keywords automatically."
    },
    {
      icon: <Globe className="text-primary" size={32} />,
      title: "Auto-Backlinks",
      description: "Our system automatically identifies and secures high-authority backlinks to boost your domain rating."
    },
    {
      icon: <BarChart3 className="text-primary" size={32} />,
      title: "Real-time Analytics",
      description: "Track your rankings, traffic, and conversions in real-time with our intuitive dashboard."
    },
    {
      icon: <ShieldCheck className="text-primary" size={32} />,
      title: "Technical SEO Audit",
      description: "Continuous monitoring and fixing of technical issues like broken links, slow speeds, and crawl errors."
    },
    {
      icon: <Layers className="text-primary" size={32} />,
      title: "Competitor Tracking",
      description: "Monitor your competitors' strategies and outrank them by identifying their keyword gaps."
    },
    {
      icon: <Clock className="text-primary" size={32} />,
      title: "24/7 Automation",
      description: "SEO never sleeps. Our AI works around the clock to ensure your site stays at the top of SERPs."
    }
  ];

  return (
    <section id="features" className="py-24 bg-slate-50">
      <div className="max-w-7xl mx-auto px-6">
        <div className="text-center max-w-3xl mx-auto mb-20">
          <h2 className="text-4xl font-bold text-slate-900 mb-6">Everything you need to dominate search</h2>
          <p className="text-lg text-slate-600">Our all-in-one platform automates the most tedious parts of SEO so you can focus on growing your business.</p>
        </div>

        <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
          {features.map((feature, index) => (
            <motion.div 
              key={index}
              whileHover={{ y: -5 }}
              className="bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition-all"
            >
              <div className="mb-6">{feature.icon}</div>
              <h3 className="text-xl font-bold text-slate-900 mb-4">{feature.title}</h3>
              <p className="text-slate-600 leading-relaxed">{feature.description}</p>
            </motion.div>
          ))}
        </div>
      </div>
    </section>
  );
};

const HowItWorks = () => {
  const steps = [
    {
      number: "01",
      title: "Connect Your Site",
      description: "Integrate your website and Google Search Console in seconds. Our AI starts analysing your current performance immediately."
    },
    {
      number: "02",
      title: "AI Strategy Generation",
      description: "Auto-Seo identifies the high-impact keywords your competitors are ranking for and creates a custom growth roadmap."
    },
    {
      number: "03",
      title: "Automated Execution",
      description: "Sit back as our AI generates content, builds backlinks, and optimises your site structure daily to drive results."
    }
  ];

  return (
    <section id="how-it-works" className="py-24">
      <div className="max-w-7xl mx-auto px-6">
        <div className="grid lg:grid-cols-2 gap-16 items-center">
          <div className="relative order-2 lg:order-1">
            <div className="flex justify-center items-center">
              <img 
                src="https://scontent-mad1-1.xx.fbcdn.net/v/t39.30808-6/642751797_122107050483247971_40018541521874084_n.jpg?_nc_cat=103&ccb=1-7&_nc_sid=13d280&_nc_ohc=EuSoTZAaLiUQ7kNvwEZeCwW&_nc_oc=Adn94uMTbfmzBNDPOS_Oou9QahwVbFmtVAe9GsvP2Twqo1-qz-xFIMsziHJjcKsglOA&_nc_zt=23&_nc_ht=scontent-mad1-1.xx&_nc_gid=M-x9ECra-sM9V3mL0npsGA&_nc_ss=8&oh=00_AfsKHQFp97Bdzsyz9C0XfWVG6o_a6rlKFZVUOqnVeqWy9A&oe=69A9FFEF" 
                alt="AI SEO Automation Workflow" 
                className="w-full h-auto drop-shadow-2xl transform hover:scale-105 transition-transform duration-700 rounded-3xl"
                referrerPolicy="no-referrer"
              />
            </div>
            <div className="absolute -bottom-6 -right-6 bg-white p-6 rounded-2xl shadow-xl border border-slate-100 max-w-[240px] z-10">
              <div className="flex items-center gap-2 mb-2">
                <CheckCircle2 className="text-emerald-500" size={20} />
                <span className="text-sm font-bold text-slate-900">Optimisation Complete</span>
              </div>
              <p className="text-xs text-slate-500">12 new articles published and 4 high-quality backlinks secured today.</p>
            </div>
          </div>
          <div className="order-1 lg:order-2">
            <h2 className="text-4xl font-bold text-slate-900 mb-8 leading-tight">How Auto-Seo puts your growth on auto-pilot 🚀</h2>
            <div className="space-y-10">
              {steps.map((step, index) => (
                <div key={index} className="flex gap-6">
                  <div className="flex-shrink-0 w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center font-bold text-xl">
                    {step.number}
                  </div>
                  <div>
                    <h3 className="text-xl font-bold text-slate-900 mb-2">{step.title}</h3>
                    <p className="text-slate-600 leading-relaxed">{step.description}</p>
                  </div>
                </div>
              ))}
            </div>
            <button className="mt-12 btn-primary">Get Started Now</button>
          </div>
        </div>
      </div>
    </section>
  );
};

const Pricing = () => {
  const plans = [
    {
      name: "Starter",
      price: "£77",
      description: "Perfect for small blogs and personal sites.",
      features: ["25 AI Articles / month", "Basic Backlink Building", "Weekly SEO Audit", "1 Website", "Email Support"],
      cta: "Start Free Trial",
      popular: false
    },
    {
      name: "Professional",
      price: "£149",
      description: "Best for growing businesses and startups.",
      features: ["50 AI Articles / month", "Advanced Backlink Building", "Daily SEO Audit", "3 Websites", "Priority Support", "Competitor Analysis"],
      cta: "Start Free Trial",
      popular: true
    },
    {
      name: "Enterprise",
      price: "£499",
      description: "For agencies and large scale operations.",
      features: ["Unlimited AI Articles", "Premium PR Backlinks", "Real-time Monitoring", "Unlimited Websites", "Dedicated Account Manager", "API Access"],
      cta: "Contact Sales",
      popular: false
    }
  ];

  return (
    <section id="pricing" className="py-24 bg-slate-50">
      <div className="max-w-7xl mx-auto px-6">
        <div className="text-center max-w-3xl mx-auto mb-20">
          <h2 className="text-4xl font-bold text-slate-900 mb-6">Simple, transparent pricing</h2>
          <p className="text-lg text-slate-600">Choose the plan that fits your growth ambitions. No hidden fees.</p>
        </div>

        <div className="grid md:grid-cols-3 gap-8">
          {plans.map((plan, index) => (
            <div 
              key={index}
              className={`relative bg-white p-10 rounded-3xl border ${plan.popular ? 'border-primary shadow-xl scale-105 z-10' : 'border-slate-200 shadow-sm'} flex flex-col`}
            >
              {plan.popular && (
                <span className="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-primary text-white text-xs font-bold px-4 py-1 rounded-full uppercase tracking-widest">
                  Most Popular
                </span>
              )}
              <h3 className="text-xl font-bold text-slate-900 mb-2">{plan.name}</h3>
              <div className="flex items-baseline gap-1 mb-4">
                <span className="text-4xl font-extrabold text-slate-900">{plan.price}</span>
                <span className="text-slate-500">/mo</span>
              </div>
              <p className="text-sm text-slate-600 mb-8">{plan.description}</p>
              <div className="space-y-4 mb-10 flex-grow">
                {plan.features.map((feature, fIndex) => (
                  <div key={fIndex} className="flex items-center gap-3">
                    <CheckCircle2 className="text-primary" size={18} />
                    <span className="text-sm text-slate-700">{feature}</span>
                  </div>
                ))}
              </div>
              <button className={`w-full py-4 rounded-full font-bold transition-all ${plan.popular ? 'bg-primary text-white hover:bg-primary-focus' : 'bg-slate-100 text-slate-900 hover:bg-slate-200'}`}>
                {plan.cta}
              </button>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

const FAQ = () => {
  const faqs = [
    {
      question: "How does the AI write the content?",
      answer: "Our AI uses advanced language models trained specifically on high-ranking SEO content. It analyses top-performing pages for your target keywords and generates unique, high-quality articles that match search intent."
    },
    {
      question: "Is the content safe from Google penalties?",
      answer: "Yes. We focus on 'Helpful Content' guidelines. Our AI doesn't just spin text; it researches and structures information to provide genuine value, which is exactly what Google's algorithms look for."
    },
    {
      question: "How long until I see results?",
      answer: "SEO is a long-term game, but our automated approach accelerates the process. Most clients see significant ranking improvements and traffic growth within 45-90 days of consistent automation."
    },
    {
      question: "Can I connect multiple websites?",
      answer: "Absolutely. Depending on your plan, you can manage multiple domains from a single dashboard, each with its own custom SEO strategy and content calendar."
    }
  ];

  const [openIndex, setOpenIndex] = useState<number | null>(0);

  return (
    <section id="faq" className="py-24">
      <div className="max-w-3xl mx-auto px-6">
        <h2 className="text-4xl font-bold text-slate-900 mb-12 text-center">Frequently Asked Questions</h2>
        <div className="space-y-4">
          {faqs.map((faq, index) => (
            <div key={index} className="border border-slate-200 rounded-2xl overflow-hidden">
              <button 
                className="w-full px-6 py-5 text-left flex items-center justify-between hover:bg-slate-50 transition-colors"
                onClick={() => setOpenIndex(openIndex === index ? null : index)}
              >
                <span className="font-bold text-slate-900">{faq.question}</span>
                <ChevronDown 
                  size={20} 
                  className={`text-slate-400 transition-transform ${openIndex === index ? 'rotate-180' : ''}`} 
                />
              </button>
              <AnimatePresence>
                {openIndex === index && (
                  <motion.div 
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    className="overflow-hidden"
                  >
                    <div className="px-6 pb-5 text-slate-600 leading-relaxed">
                      {faq.answer}
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

const Footer = () => {
  return (
    <footer className="bg-slate-900 text-slate-400 py-20">
      <div className="max-w-7xl mx-auto px-6">
        <div className="grid md:grid-cols-4 gap-12 mb-16">
          <div className="col-span-2">
            <div className="flex items-center gap-2 mb-6">
              <div className="w-8 h-8 bg-primary rounded-lg flex items-center justify-center text-white">
                <Zap size={18} fill="currentColor" />
              </div>
              <span className="text-xl font-extrabold tracking-tight text-white">Auto-Seo<span className="text-primary">.co.uk</span></span>
            </div>
            <p className="max-w-sm mb-8">
              The world's first fully autonomous SEO platform. We help businesses grow their organic presence without the manual grind.
            </p>
            <div className="flex gap-4">
              <div className="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center hover:bg-primary transition-colors cursor-pointer">
                <Globe size={18} />
              </div>
              <div className="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center hover:bg-primary transition-colors cursor-pointer">
                <BarChart3 size={18} />
              </div>
              <div className="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center hover:bg-primary transition-colors cursor-pointer">
                <Zap size={18} />
              </div>
            </div>
          </div>
          
          <div>
            <h4 className="text-white font-bold mb-6 uppercase text-xs tracking-widest">Product</h4>
            <ul className="space-y-4 text-sm">
              <li><a href="#" className="hover:text-white transition-colors">Features</a></li>
              <li><a href="#" className="hover:text-white transition-colors">Pricing</a></li>
              <li><a href="#" className="hover:text-white transition-colors">Case Studies</a></li>
              <li><a href="#" className="hover:text-white transition-colors">Reviews</a></li>
            </ul>
          </div>
          
          <div>
            <h4 className="text-white font-bold mb-6 uppercase text-xs tracking-widest">Company</h4>
            <ul className="space-y-4 text-sm">
              <li><a href="#" className="hover:text-white transition-colors">About Us</a></li>
              <li><a href="#" className="hover:text-white transition-colors">Blog</a></li>
              <li><a href="#" className="hover:text-white transition-colors">Privacy Policy</a></li>
              <li><a href="#" className="hover:text-white transition-colors">Terms of Service</a></li>
            </ul>
          </div>
        </div>
        
        <div className="pt-8 border-t border-slate-800 flex flex-col md:flex-row justify-between items-center gap-4 text-xs">
          <p>© 2026 Auto-Seo.co.uk. All rights reserved.</p>
          <p>Built with ❤️ for search dominance.</p>
        </div>
      </div>
    </footer>
  );
};

export default function App() {
  return (
    <div className="min-h-screen selection:bg-primary/30 selection:text-primary">
      <Navbar />
      <main>
        <Hero />
        <Features />
        <HowItWorks />
        
        {/* CTA Section */}
        <section className="py-24">
          <div className="max-w-5xl mx-auto px-6">
            <div className="bg-primary rounded-[3rem] p-12 md:p-20 text-center text-white relative overflow-hidden shadow-2xl">
              <div className="absolute top-0 left-0 w-full h-full opacity-10 pointer-events-none">
                <div className="absolute top-0 left-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2" />
                <div className="absolute bottom-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl translate-x-1/2 translate-y-1/2" />
              </div>
              <h2 className="text-4xl md:text-5xl font-extrabold mb-8 relative z-10">Ready to outrank your competitors?</h2>
              <p className="text-lg md:text-xl text-white/80 mb-10 max-w-2xl mx-auto relative z-10">
                Join 1,000+ companies using Auto-Seo to grow their organic traffic on auto-pilot.
              </p>
              <div className="flex flex-col sm:flex-row items-center justify-center gap-4 relative z-10">
                <button className="bg-white text-primary px-10 py-5 rounded-full font-bold text-lg hover:bg-slate-50 transition-all hover:shadow-xl active:scale-95">
                  Start Your Free Trial
                </button>
                <button className="bg-primary-focus text-white px-10 py-5 rounded-full font-bold text-lg hover:bg-primary-focus/80 transition-all active:scale-95">
                  Talk to an Expert
                </button>
              </div>
            </div>
          </div>
        </section>

        <Pricing />
        <FAQ />
      </main>
      <Footer />
    </div>
  );
}
