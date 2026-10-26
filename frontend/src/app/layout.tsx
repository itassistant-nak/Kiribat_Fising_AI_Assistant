import type { Metadata } from "next";
import "./globals.css";
import "leaflet/dist/leaflet.css";
import Providers from "../components/Providers";

export const metadata: Metadata = {
  title: "Kiribati AI Fisheries Assistant — Admin Dashboard",
  description: "Ministry of Fisheries & Marine Resources Development (MFMRD) - Catch Review, Meta AI Identification, and GPS Monitoring Portal",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="h-full antialiased" suppressHydrationWarning>
      <body
        className="min-h-full flex flex-col bg-slate-50 text-slate-900 font-sans selection:bg-blue-600 selection:text-white"
        suppressHydrationWarning
      >
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
