import { LucideIcon } from "lucide-react";
import { Card } from "@/components/ui/card";

interface StatsCardProps {
  title: string;
  value: string;
  icon: LucideIcon;
  trend?: string;
  trendUp?: boolean;
}

const StatsCard = ({ title, value, icon: Icon, trend, trendUp }: StatsCardProps) => {
  return (
    <Card className="p-6 shadow-card hover:shadow-elegant transition-smooth hover:scale-105 border-border">
      <div className="flex items-start justify-between mb-4">
        <div className="p-3 rounded-xl gradient-primary shadow-elegant">
          <Icon className="w-6 h-6 text-white" />
        </div>
        {trend && (
          <span
            className={`text-sm font-semibold px-3 py-1 rounded-full ${
              trendUp
                ? "bg-success/10 text-success"
                : "bg-destructive/10 text-destructive"
            }`}
          >
            {trend}
          </span>
        )}
      </div>
      <h3 className="text-sm font-medium text-muted-foreground mb-1">{title}</h3>
      <p className="text-3xl font-bold text-foreground">{value}</p>
    </Card>
  );
};

export default StatsCard;
