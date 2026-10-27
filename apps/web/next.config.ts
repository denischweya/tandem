import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  transpilePackages: ['@tandem/shared'],
  reactStrictMode: true,
};

export default nextConfig;
